use chrono::{Duration, NaiveDate, NaiveTime};
use ext_php_rs::boxed::ZBox;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, ZendStr, Zval};
use ext_php_rs::zend::ce;
use xlsx_batch_reader::read::XlsxBook;
use xlsx_batch_reader::CellValue;

/// Rows per internal batch pulled from the sheet iterator. Each batch is
/// converted to owned values immediately and released before the next one,
/// so memory stays proportional to one batch, not the whole sheet.
const BATCH_SIZE: usize = 1000;

/// Owned copy of a parsed cell, decoupled from the borrow of the workbook.
enum OwnedCell {
    Null,
    Bool(bool),
    Int(i64),
    Float(f64),
    Date(String),
    Time(String),
    DateTime(String),
    Str(String),
}

fn excel_base_date() -> NaiveDate {
    NaiveDate::from_ymd_opt(1899, 12, 30).unwrap()
}

/// Format an Excel serial date/time value as an ISO string, matching the
/// conversion used by the xlsx_batch_reader crate (days since 1899-12-30).
fn format_datetime(serial: f64) -> String {
    let base = excel_base_date();
    let days = serial.trunc() as i64;
    let frac = serial - serial.trunc();
    let date = base + Duration::try_days(days).unwrap_or(Duration::zero());
    if frac == 0.0 {
        date.format("%Y-%m-%d").to_string()
    } else {
        let seconds = (frac * 86400.0).round() as i64;
        let dt = date.and_hms_opt(0, 0, 0).unwrap() + Duration::try_seconds(seconds).unwrap_or(Duration::zero());
        dt.format("%Y-%m-%d %H:%M:%S").to_string()
    }
}

fn format_time(serial: f64) -> String {
    let seconds = (serial * 86400.0).round() as i64;
    let t = NaiveTime::from_num_seconds_from_midnight_opt(seconds as u32, 0).unwrap_or(NaiveTime::MIN);
    t.format("%H:%M:%S").to_string()
}

impl OwnedCell {
    fn from_cell(cell: &CellValue<'_>) -> Self {
        match cell {
            CellValue::Blank => OwnedCell::Null,
            CellValue::Bool(b) => OwnedCell::Bool(*b),
            CellValue::Number(n) => {
                if n.fract() == 0.0 && n.abs() < 9.22e18 {
                    OwnedCell::Int(*n as i64)
                } else {
                    OwnedCell::Float(*n)
                }
            }
            CellValue::Date(n) => OwnedCell::Date(format_datetime(*n)),
            CellValue::Time(n) => OwnedCell::Time(format_time(*n)),
            CellValue::Datetime(n) => OwnedCell::DateTime(format_datetime(*n)),
            CellValue::Shared(s) => OwnedCell::Str(s.as_str().to_string()),
            CellValue::String(s) => OwnedCell::Str(s.clone()),
            CellValue::Error(e) => OwnedCell::Str(e.clone()),
            CellValue::Formula(f) => OwnedCell::Str(f.clone()),
        }
    }

    fn set_zval(&self, zv: &mut Zval) {
        match self {
            OwnedCell::Null => {}
            OwnedCell::Bool(b) => zv.set_bool(*b),
            OwnedCell::Int(i) => zv.set_long(*i),
            OwnedCell::Float(f) => zv.set_double(*f),
            OwnedCell::Date(s) | OwnedCell::Time(s) | OwnedCell::DateTime(s) | OwnedCell::Str(s) => {
                zv.set_zend_string(ZendStr::new(s.as_bytes(), false));
            }
        }
    }
}

/// Self-referential holder: the streaming sheet borrows shared strings and
/// the zip archive from the workbook, so the book must outlive the sheet.
///
/// The book is kept in a `Box` so its address is stable, and the sheet's
/// borrow lifetime is erased. The invariant that the book outlives the sheet
/// is maintained by the field drop order (sheet is declared first, so it is
/// dropped before the book). ouroboros cannot express this because the sheet
/// must be constructed from `&mut book` but accessed through `&mut self`
/// afterwards.
struct SheetHolder {
    sheet: Option<Box<xlsx_batch_reader::read::XlsxSheet<'static>>>,
    // Never read after construction: kept alive so the sheet's erased
    // borrows stay valid, and dropped after `sheet` (declaration order).
    #[allow(dead_code)]
    book: Option<Box<XlsxBook>>,
}

impl SheetHolder {
    fn open(path: &str, sheet_name: &str, has_headers: bool) -> Result<Self, String> {
        let mut book = Box::new(XlsxBook::new(path, true).map_err(|e| e.to_string())?);
        let sname = sheet_name.to_string();
        let sheet = book
            .get_sheet_by_name(&sname, BATCH_SIZE, 0, 1, xlsx_batch_reader::MAX_COL_NUM, has_headers)
            .map_err(|e| e.to_string())?;
        // Safety: `sheet` borrows fields of `book`. `book` lives in a heap
        // Box at a stable address and outlives `sheet` (field drop order),
        // so erasing the borrow lifetime is sound.
        let sheet = Box::new(unsafe { std::mem::transmute::<xlsx_batch_reader::read::XlsxSheet<'_>, xlsx_batch_reader::read::XlsxSheet<'static>>(sheet) });
        Ok(Self {
            sheet: Some(sheet),
            book: Some(book),
        })
    }

    fn sheet_mut(&mut self) -> &mut xlsx_batch_reader::read::XlsxSheet<'static> {
        self.sheet.as_mut().expect("sheet holder is initialized")
    }

    fn next_batch(&mut self) -> Result<Option<(Vec<u32>, Vec<Vec<OwnedCell>>)>, String> {
        match self
            .sheet_mut()
            .next()
            .map(|res| res.map_err(|e| e.to_string()))
        {
            Some(Ok((rows, data))) => {
                let owned = data
                    .into_iter()
                    .map(|row| row.iter().map(OwnedCell::from_cell).collect())
                    .collect();
                Ok(Some((rows, owned)))
            }
            Some(Err(e)) => Err(e),
            None => Ok(None),
        }
    }

    fn header_row(&mut self) -> Result<Option<Vec<OwnedCell>>, String> {
        match self.sheet_mut().get_header_row() {
            Ok((_, cells)) => Ok(Some(cells.iter().map(OwnedCell::from_cell).collect())),
            Err(e) => Err(e.to_string()),
        }
    }
}

/// High-performance streaming XLSX reader.
///
/// Reads `.xlsx` / `.xlsm` files row by row from the sheet XML directly,
/// keeping memory usage proportional to one internal batch of rows instead
/// of the whole file. Implements PHP's `Iterator` interface.
#[php_class]
#[php(implements(ce = ce::iterator, stub = "\\Iterator"))]
pub struct XlsxStreamer {
    holder: SheetHolder,
    path: String,
    sheet_name: String,
    headers: Vec<ZBox<ZendStr>>,
    has_headers: bool,
    batch: Option<(Vec<u32>, Vec<Vec<OwnedCell>>)>,
    batch_row: usize,
    row: usize,
    fetched: bool,
    done: bool,
}

impl XlsxStreamer {
    fn open_sheet(path: &str, sheet: &str, has_headers: bool) -> Result<(SheetHolder, Vec<ZBox<ZendStr>>), String> {
        let mut holder = SheetHolder::open(path, sheet, has_headers)?;
        let headers = if has_headers {
            holder
                .header_row()?
                .unwrap_or_default()
                .iter()
                .filter_map(|c| match c {
                    OwnedCell::Str(s) | OwnedCell::Date(s) | OwnedCell::Time(s) | OwnedCell::DateTime(s) => {
                        Some(ZendStr::new(s.as_bytes(), false))
                    }
                    _ => None,
                })
                .collect()
        } else {
            Vec::new()
        };
        Ok((holder, headers))
    }

    /// Pull the next row's owned cells into `self.batch`, or mark done.
    fn pull_row(&mut self) -> Result<(), String> {
        loop {
            let Some((_, rows)) = &mut self.batch else {
                match self.holder.next_batch()? {
                    Some((nums, data)) => self.batch = Some((nums, data)),
                    None => {
                        self.done = true;
                        self.fetched = false;
                        return Ok(());
                    }
                }
                self.batch_row = 0;
                continue;
            };
            if self.batch_row < rows.len() {
                return Ok(());
            }
            self.batch = None;
        }
    }
}

#[php_impl]
impl XlsxStreamer {
    /// Open an XLSX workbook for streaming.
    ///
    /// @param string $path Path to the .xlsx / .xlsm file.
    /// @param string|null $sheet Sheet name to read (default: first visible sheet).
    /// @param bool|null $has_headers When true, the first row is treated as
    ///     the header row and `current()` returns associative arrays.
    ///
    /// @throws \Exception If the file cannot be opened or the sheet is missing.
    #[php(optional = sheet)]
    pub fn __construct(
        path: String,
        sheet: Option<String>,
        has_headers: Option<bool>,
    ) -> PhpResult<Self> {
        let has_headers = has_headers.unwrap_or(false);
        let book = XlsxBook::new(&path, false).map_err(|e| format!("Failed to open XLSX file '{}': {}", path, e))?;
        let sheet_name = match &sheet {
            Some(name) => name.clone(),
            None => book
                .get_visible_sheets()
                .first()
                .cloned()
                .ok_or_else(|| format!("No visible sheets in '{}'", path))?,
        };
        let (holder, headers) = Self::open_sheet(&path, &sheet_name, has_headers)
            .map_err(|e| format!("Failed to open sheet '{}': {}", sheet_name, e))?;
        Ok(Self {
            holder,
            path,
            sheet_name,
            headers,
            has_headers,
            batch: None,
            batch_row: 0,
            row: 0,
            fetched: false,
            done: false,
        })
    }

    /// Return the current row as an array, or null when iteration is done.
    ///
    /// When headers are enabled the array is keyed by header name, otherwise
    /// it is a sequential list. Blank cells become null, numbers become int
    /// or float, dates/times become ISO-8601 strings.
    ///
    /// @return array|null
    pub fn current(&mut self) -> Option<ZBox<ZendHashTable>> {
        if !self.fetched || self.done {
            return None;
        }
        let (_, rows) = self.batch.as_ref()?;
        let row = rows.get(self.batch_row)?;
        let mut ht = ZendHashTable::with_capacity(row.len() as u32);
        if self.has_headers {
            for (i, cell) in row.iter().enumerate() {
                let mut zv = Zval::new();
                cell.set_zval(&mut zv);
                match self.headers.get(i) {
                    Some(key) => {
                        let _ = ht.insert(key, zv);
                    }
                    None => {
                        let _ = ht.insert(i as i64, zv);
                    }
                }
            }
        } else {
            for cell in row.iter() {
                let mut zv = Zval::new();
                cell.set_zval(&mut zv);
                let _ = ht.push(zv);
            }
        }
        Some(ht)
    }

    /// Return the 0-based index of the current row.
    pub fn key(&self) -> usize {
        self.row.saturating_sub(1)
    }

    /// Advance to the next row. Does nothing once iteration is complete.
    pub fn next(&mut self) -> PhpResult<()> {
        if self.done {
            return Ok(());
        }
        if self.fetched {
            self.batch_row += 1;
        }
        if let Err(e) = self.pull_row() {
            return Err(PhpException::from(e));
        }
        if !self.done {
            self.fetched = true;
            self.row += 1;
        }
        Ok(())
    }

    /// Rewind to the first row. The sheet is re-opened from the start.
    pub fn rewind(&mut self) -> PhpResult<()> {
        let (holder, headers) = Self::open_sheet(&self.path, &self.sheet_name, self.has_headers)
            .map_err(|e| PhpException::from(e))?;
        self.holder = holder;
        self.headers = headers;
        self.batch = None;
        self.batch_row = 0;
        self.row = 0;
        self.fetched = false;
        self.done = false;
        Ok(())
    }

    /// Whether the current position is valid. Lazily reads the next record
    /// when iteration has not yet started.
    pub fn valid(&mut self) -> PhpResult<bool> {
        if self.done {
            return Ok(false);
        }
        if !self.fetched {
            self.next()?;
        }
        Ok(!self.done)
    }

    /// Advance to the next row and return it in a single call.
    ///
    /// @return array|null
    pub fn next_row(&mut self) -> PhpResult<Option<ZBox<ZendHashTable>>> {
        self.next()?;
        Ok(self.current())
    }

    /// Read up to `$count` rows in a single call, amortizing the per-call
    /// overhead across the whole batch.
    ///
    /// Returns an array of row arrays (fewer than `$count` at end of file),
    /// or null when no rows remain.
    ///
    /// @param int $count Maximum number of rows to read.
    /// @return array|null
    pub fn next_rows(&mut self, count: i64) -> PhpResult<Option<ZBox<ZendHashTable>>> {
        let count = count.clamp(0, i32::MAX as i64) as u32;
        let mut out = ZendHashTable::with_capacity(count);
        for _ in 0..count {
            self.next()?;
            if self.done {
                break;
            }
            if let Some(row) = self.current() {
                out.push(row)?;
            }
        }
        if out.len() == 0 {
            Ok(None)
        } else {
            Ok(Some(out))
        }
    }

    /// Return the header row as a list, or null when headers are disabled.
    ///
    /// @return array|null
    pub fn headers(&self) -> Option<Vec<String>> {
        if self.has_headers {
            Some(
                self.headers
                    .iter()
                    .map(|h| h.as_str().map(ToString::to_string).unwrap_or_default())
                    .collect(),
            )
        } else {
            None
        }
    }

    /// Return the name of the sheet being read.
    ///
    /// @return string
    pub fn sheet_name(&self) -> String {
        self.sheet_name.clone()
    }

    /// List the visible sheet names of a workbook without opening any sheet.
    ///
    /// @param string $path Path to the .xlsx / .xlsm file.
    /// @return string[]
    pub fn sheets(path: String) -> PhpResult<Vec<String>> {
        let book = XlsxBook::new(&path, false)
            .map_err(|e| format!("Failed to open XLSX file '{}': {}", path, e))?;
        Ok(book.get_visible_sheets().clone())
    }
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module.class::<XlsxStreamer>()
}
