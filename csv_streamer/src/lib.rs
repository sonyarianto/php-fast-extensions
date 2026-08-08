use std::fs::File;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::convert::IntoZval;
use ext_php_rs::error::Result as ExtResult;
use ext_php_rs::flags::DataType;
use ext_php_rs::prelude::*;
use ext_php_rs::types::ZendHashTable;
use ext_php_rs::types::ZendStr;
use ext_php_rs::types::Zval;
use ext_php_rs::zend::ce;

/// A raw CSV cell, pushed into PHP as a binary string without UTF-8
/// validation. PHP strings are byte strings, so validation would be wasted
/// work on every cell.
struct CsvCell<'a>(&'a [u8]);

impl IntoZval for CsvCell<'_> {
    const TYPE: DataType = DataType::String;
    const NULLABLE: bool = false;

    fn set_zval(self, zv: &mut Zval, persistent: bool) -> ExtResult<()> {
        zv.set_zend_string(ZendStr::new(self.0, persistent));
        Ok(())
    }
}

/// Validate a parsed row as UTF-8, throwing an exception on the first
/// invalid byte. Only used in strict mode.
fn validate_record(record: &csv::ByteRecord) -> PhpResult<()> {
    match std::str::from_utf8(record.as_slice()) {
        Ok(_) => Ok(()),
        Err(e) => Err(format!(
            "CSV row contains invalid UTF-8 (at byte offset {})",
            e.valid_up_to()
        )
        .into()),
    }
}

/// High-performance streaming CSV reader.
///
/// Implements PHP's `Iterator` interface, so it can be used directly in a
/// `foreach` loop. Rows are parsed one at a time into a single reused buffer,
/// keeping memory usage constant regardless of file size.
#[php_class]
#[php(implements(ce = ce::iterator, stub = "\\Iterator"))]
pub struct CsvStreamer {
    reader: csv::Reader<File>,
    record: csv::ByteRecord,
    headers: Vec<ZBox<ZendStr>>,
    has_headers: bool,
    strict: bool,
    row: usize,
    fetched: bool,
    done: bool,
}

#[php_impl]
impl CsvStreamer {
    /// Open a CSV file for streaming.
    ///
    /// @param string $path Path to the CSV file.
    /// @param string|null $delimiter Field delimiter, single character (default ",").
    /// @param bool|null $has_headers When true, the first row is treated as
    ///     the header row and `current()` returns associative arrays.
    /// @param bool|null $strict When true, rows are validated as UTF-8 and an
    ///     exception is thrown on invalid input. When false (default), raw
    ///     bytes are passed through untouched, which is faster.
    ///
    /// @throws \Exception If the file cannot be opened.
    #[php(optional = delimiter)]
    pub fn __construct(
        path: String,
        delimiter: Option<String>,
        has_headers: Option<bool>,
        strict: Option<bool>,
    ) -> PhpResult<Self> {
        let delim = match &delimiter {
            Some(d) if d.len() == 1 => d.as_bytes()[0],
            Some(_) => return Err("Delimiter must be a single character".into()),
            None => b',',
        };
        let has_headers = has_headers.unwrap_or(false);
        let strict = strict.unwrap_or(false);

        let mut reader = csv::ReaderBuilder::new()
            .has_headers(false)
            .flexible(true)
            .delimiter(delim)
            .buffer_capacity(1 << 20)
            .from_path(&path)
            .map_err(|e| format!("Failed to open CSV file '{}': {}", path, e))?;

        let headers = if has_headers {
            let mut first = csv::ByteRecord::new();
            match reader.read_byte_record(&mut first) {
                Ok(_) => {
                    if strict {
                        validate_record(&first)?;
                    }
                    first
                        .iter()
                        .map(|f| ZendStr::new(f.strip_prefix(b"\xef\xbb\xbf").unwrap_or(f), false))
                        .collect()
                }
                Err(e) => return Err(PhpException::from(e.to_string())),
            }
        } else {
            Vec::new()
        };

        Ok(Self {
            reader,
            record: csv::ByteRecord::new(),
            headers,
            has_headers,
            strict,
            row: 0,
            fetched: false,
            done: false,
        })
    }

    /// Return the current row as an array, or null when iteration is done.
    ///
    /// When headers are enabled the array is keyed by header name, otherwise
    /// it is a sequential list. Header keys are pre-built PHP strings, so no
    /// key allocation or hashing is performed per row.
    ///
    /// @return array|null
    pub fn current(&mut self) -> Option<ZBox<ZendHashTable>> {
        if !self.fetched || self.done {
            return None;
        }
        let mut ht = ZendHashTable::with_capacity(self.record.len() as u32);
        if self.has_headers {
            for (i, field) in self.record.iter().enumerate() {
                match self.headers.get(i) {
                    Some(key) => {
                        let _ = ht.insert(key, CsvCell(field));
                    }
                    None => {
                        let _ = ht.insert(i as i64, CsvCell(field));
                    }
                }
            }
        } else {
            for field in self.record.iter() {
                let _ = ht.push(CsvCell(field));
            }
        }
        Some(ht)
    }

    /// Return the 0-based index of the current row.
    pub fn key(&self) -> usize {
        self.row.saturating_sub(1)
    }

    /// Advance to the next row. Does nothing once iteration is complete.
    #[allow(clippy::should_implement_trait)]
    pub fn next(&mut self) -> PhpResult<()> {
        if self.done {
            return Ok(());
        }
        match self.reader.read_byte_record(&mut self.record) {
            Ok(true) => {
                if self.strict {
                    validate_record(&self.record)?;
                }
                self.fetched = true;
                self.row += 1;
            }
            Ok(false) => {
                self.done = true;
                self.fetched = false;
            }
            Err(e) => return Err(PhpException::from(e.to_string())),
        }
        Ok(())
    }

    /// Advance to the next row and return it in a single call.
    ///
    /// Equivalent to calling `next()` followed by `current()`, but with a
    /// fraction of the per-row call overhead. Returns null at end of file.
    ///
    /// @return array|null
    pub fn next_row(&mut self) -> PhpResult<Option<ZBox<ZendHashTable>>> {
        self.next()?;
        Ok(self.current())
    }

    /// Rewind to the first row. The file is re-opened at offset 0 and the
    /// header row (if enabled) is re-read.
    pub fn rewind(&mut self) -> PhpResult<()> {
        self.reader
            .seek(csv::Position::new())
            .map_err(|e| PhpException::from(e.to_string()))?;
        self.row = 0;
        self.fetched = false;
        self.done = false;
        if self.has_headers {
            let mut first = csv::ByteRecord::new();
            match self.reader.read_byte_record(&mut first) {
                Ok(_) => {
                    if self.strict {
                        validate_record(&first)?;
                    }
                    self.headers = first
                        .iter()
                        .map(|f| ZendStr::new(f.strip_prefix(b"\xef\xbb\xbf").unwrap_or(f), false))
                        .collect();
                }
                Err(e) => return Err(PhpException::from(e.to_string())),
            }
        }
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
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module.class::<CsvStreamer>()
}
