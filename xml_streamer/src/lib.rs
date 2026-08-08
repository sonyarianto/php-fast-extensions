use std::fs::File;
use std::io::BufReader;

use ext_php_rs::boxed::ZBox;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, Zval};
use ext_php_rs::zend::ce;
use quick_xml::escape::unescape;
use quick_xml::events::{BytesStart, Event};
use quick_xml::name::QName;
use quick_xml::Reader;

/// Maximum element nesting depth accepted inside a row.
const MAX_DEPTH: usize = 512;

/// A parsed element value: a scalar leaf, or an assoc array of children.
enum ElemValue {
    Scalar(Zval),
    Array(ZBox<ZendHashTable>),
}

impl ElemValue {
    fn into_zval(self) -> Zval {
        match self {
            ElemValue::Scalar(zv) => zv,
            ElemValue::Array(ht) => {
                let mut zv = Zval::new();
                zv.set_hashtable(ht);
                zv
            }
        }
    }
}

/// Extract the namespace-prefix-free part of a qualified name.
fn local_name<'a>(n: QName<'a>) -> &'a [u8] {
    let raw = n.0;
    match raw.iter().rposition(|&b| b == b':') {
        Some(i) => &raw[i + 1..],
        None => raw,
    }
}

fn xml_err(reader: &Reader<BufReader<File>>, e: quick_xml::Error) -> String {
    format!("XML parse error at byte {}: {}", reader.buffer_position(), e)
}

/// Fill a zval with a leaf value. In typed mode, text is inferred as
/// bool/int/float when it looks like one, else kept as string.
fn set_scalar(zv: &mut Zval, text: &str, typed: bool) -> PhpResult<()> {
    if typed {
        match text {
            "true" => {
                zv.set_bool(true);
                return Ok(());
            }
            "false" => {
                zv.set_bool(false);
                return Ok(());
            }
            _ => {}
        }
        let digits = text.strip_prefix(['+', '-']).unwrap_or(text);
        if !digits.is_empty() && digits.bytes().all(|b| b.is_ascii_digit()) {
            if let Ok(i) = text.parse::<i64>() {
                zv.set_long(i);
                return Ok(());
            }
        }
        let lower = text.to_ascii_lowercase();
        if lower.contains(['.', 'e']) {
            if let Ok(f) = text.parse::<f64>() {
                if f.is_finite() {
                    zv.set_double(f);
                    return Ok(());
                }
            }
        }
    }
    zv.set_string(text, false)?;
    Ok(())
}

/// Turn the attributes of an element into an assoc array, skipping xmlns
/// declarations. Returns None when the element has no usable attributes.
fn build_attrs(e: &BytesStart) -> PhpResult<Option<ZBox<ZendHashTable>>> {
    let mut attrs: Vec<(String, Zval)> = Vec::new();
    for attr in e.attributes() {
        let attr = attr.map_err(|err| format!("invalid attribute: {err}"))?;
        let key = attr.key.as_ref();
        let local = match key.iter().rposition(|&b| b == b':') {
            Some(i) => &key[i + 1..],
            None => key,
        };
        if local == b"xmlns" {
            continue;
        }
        let raw = std::str::from_utf8(&attr.value)
            .map_err(|e| format!("non-UTF-8 attribute value: {e}"))?;
        let value = unescape(raw).map_err(|e| format!("invalid entity in attribute: {e}"))?;
        let mut zv = Zval::new();
        zv.set_string(&value, false)?;
        attrs.push((String::from_utf8_lossy(local).into_owned(), zv));
    }
    if attrs.is_empty() {
        return Ok(None);
    }
    let mut ht = ZendHashTable::with_capacity(attrs.len() as u32);
    for (k, v) in attrs {
        ht.insert(k, v)?;
    }
    Ok(Some(ht))
}

/// Accumulate a text event into the element's text chunk list.
fn push_text(text: &mut Vec<String>, bytes: &[u8]) -> PhpResult<()> {
    let raw = std::str::from_utf8(bytes).map_err(|e| format!("non-UTF-8 text: {e}"))?;
    let decoded = unescape(raw).map_err(|e| format!("invalid entity in text: {e}"))?;
    text.push(decoded.into_owned());
    Ok(())
}

/// Accumulate a CDATA event; CDATA content is raw and must not be unescaped.
fn push_cdata(text: &mut Vec<String>, bytes: &[u8]) -> PhpResult<()> {
    let raw = std::str::from_utf8(bytes).map_err(|e| format!("non-UTF-8 text: {e}"))?;
    text.push(raw.to_string());
    Ok(())
}

/// Collect children with the same local name so repeated tags become lists.
fn push_group(groups: &mut Vec<(String, Vec<Zval>)>, name: String, val: Zval) {
    match groups.iter_mut().find(|(n, _)| *n == name) {
        Some((_, vals)) => vals.push(val),
        None => groups.push((name, vec![val])),
    }
}

/// Fold grouped children into an assoc array: single occurrence becomes the
/// value itself, repeated occurrences become a list of values.
fn fold_children(groups: Vec<(String, Vec<Zval>)>) -> PhpResult<ZBox<ZendHashTable>> {
    let total: usize = groups.iter().map(|(_, v)| v.len()).sum();
    let mut ht = ZendHashTable::with_capacity(total as u32);
    for (name, vals) in groups {
        if vals.len() == 1 {
            ht.insert(name, vals.into_iter().next().unwrap())?;
        } else {
            let mut list = ZendHashTable::with_capacity(vals.len() as u32);
            for v in vals {
                list.push(v)?;
            }
            let mut lz = Zval::new();
            lz.set_hashtable(list);
            ht.insert(name, lz)?;
        }
    }
    Ok(ht)
}

/// Build a leaf element value: attributes become an `@attributes` assoc, text
/// becomes the scalar itself (or `@value` when attributes are present).
fn leaf_value(
    attrs: Option<ZBox<ZendHashTable>>,
    text: &str,
    typed: bool,
) -> PhpResult<ElemValue> {
    if let Some(attrs) = attrs {
        let mut ht = ZendHashTable::with_capacity(2);
        let mut az = Zval::new();
        az.set_hashtable(attrs);
        ht.insert("@attributes", az)?;
        if !text.is_empty() {
            let mut tv = Zval::new();
            set_scalar(&mut tv, text, typed)?;
            ht.insert("@value", tv)?;
        }
        Ok(ElemValue::Array(ht))
    } else {
        let mut zv = Zval::new();
        set_scalar(&mut zv, text, typed)?;
        Ok(ElemValue::Scalar(zv))
    }
}

/// Parse one element (its opening tag has been consumed) into a PHP value.
/// Children become an assoc array keyed by local name (repeated tags become
/// lists), attributes land under `@attributes`, and text under `@value`.
/// `force_assoc` wraps scalar results (leaf rows) into `{"@value": ...}`.
fn parse_element(
    reader: &mut Reader<BufReader<File>>,
    typed: bool,
    depth: usize,
    open_name: QName,
    attrs: Option<ZBox<ZendHashTable>>,
    self_closing: bool,
    force_assoc: bool,
) -> PhpResult<ElemValue> {
    if depth > MAX_DEPTH {
        return Err("XML nesting too deep (>512)".into());
    }
    let mut groups: Vec<(String, Vec<Zval>)> = Vec::new();
    let mut text: Vec<String> = Vec::new();
    let mut buf = Vec::new();

    if !self_closing {
        loop {
            match reader.read_event_into(&mut buf).map_err(|e| xml_err(reader, e))? {
                Event::Start(e) => {
                    let name = e.name();
                    let lname = String::from_utf8_lossy(local_name(name)).into_owned();
                    let child_attrs = build_attrs(&e)?;
                    let child = parse_element(reader, typed, depth + 1, name, child_attrs, false, false)?;
                    push_group(&mut groups, lname, child.into_zval());
                }
                Event::Empty(e) => {
                    let lname = String::from_utf8_lossy(local_name(e.name())).into_owned();
                    let child_attrs = build_attrs(&e)?;
                    let child = parse_element(
                        reader,
                        typed,
                        depth + 1,
                        e.name(),
                        child_attrs,
                        true,
                        false,
                    )?;
                    push_group(&mut groups, lname, child.into_zval());
                }
                Event::End(e) => {
                    if e.name() == open_name {
                        break;
                    }
                    return Err(format!(
                        "mismatched end tag </{}> inside <{}>",
                        String::from_utf8_lossy(e.name().as_ref()),
                        String::from_utf8_lossy(open_name.as_ref())
                    )
                    .into());
                }
                Event::Text(t) => push_text(&mut text, t.as_ref())?,
                Event::CData(t) => push_cdata(&mut text, t.as_ref())?,
                Event::Eof => return Err("unexpected end of file inside element".into()),
                _ => {} // comments, PI, declaration, doctype
            }
        }
    }

    let value = if groups.is_empty() {
        leaf_value(attrs, &text.concat(), typed)?
    } else {
        let mut ht = fold_children(groups)?;
        let collapsed = text
            .join(" ")
            .split_whitespace()
            .collect::<Vec<&str>>()
            .join(" ");
        if !collapsed.is_empty() {
            let mut tv = Zval::new();
            set_scalar(&mut tv, &collapsed, typed)?;
            ht.insert("@value", tv)?;
        }
        if let Some(attrs) = attrs {
            let mut az = Zval::new();
            az.set_hashtable(attrs);
            ht.insert("@attributes", az)?;
        }
        ElemValue::Array(ht)
    };

    if force_assoc {
        if let ElemValue::Scalar(zv) = value {
            let mut ht = ZendHashTable::with_capacity(1);
            ht.insert("@value", zv)?;
            return Ok(ElemValue::Array(ht));
        }
    }
    Ok(value)
}

/// Implements PHP's `Iterator` interface.
#[php_class]
#[php(implements(ce = ce::iterator, stub = "\\Iterator"))]
pub struct XmlStreamer {
    path: String,
    reader: Option<Reader<BufReader<File>>>,
    row_tag: String,
    typed: bool,
    current: Option<ZBox<ZendHashTable>>,
    row: i64,
    done: bool,
    fetched: bool,
}

#[php_impl]
impl XmlStreamer {
    /// Open an XML file for streaming.
    ///
    /// Rows are the elements whose (namespace-prefix-free) name equals `row`,
    /// found at any depth of the document.
    ///
    /// @param string $path Path to the XML file.
    /// @param string|null $row Local name of the row element (default "row").
    /// @param bool|null $typed Infer int/float/bool types from text (default false).
    /// @throws \Exception When the file cannot be opened.
    #[php(optional = row)]
    pub fn __construct(path: String, row: Option<String>, typed: Option<bool>) -> PhpResult<Self> {
        let file = File::open(&path)
            .map_err(|e| format!("Failed to open XML file '{}': {}", path, e))?;
        let mut reader = Reader::from_reader(BufReader::new(file));
        reader.config_mut().trim_text(true);
        Ok(Self {
            path,
            reader: Some(reader),
            row_tag: row.unwrap_or_else(|| "row".to_string()),
            typed: typed.unwrap_or(false),
            current: None,
            row: 0,
            done: false,
            fetched: false,
        })
    }

    /// Return the current row as an assoc array, or null when iteration is
    /// done.
    ///
    /// Child elements become keys (repeated tags become lists), attributes
    /// land under `@attributes`, direct text under `@value`. Leaf rows are
    /// wrapped as `{"@value": ...}`.
    ///
    /// @return array|null
    pub fn current(&mut self) -> Option<Zval> {
        if !self.fetched || self.done {
            return None;
        }
        self.current.clone().map(|ht| {
            let mut zv = Zval::new();
            zv.set_hashtable(ht);
            zv
        })
    }

    /// The 0-based index of the current row.
    pub fn key(&self) -> i64 {
        self.row.saturating_sub(1).max(0)
    }

    /// Advance to the next row. Does nothing once iteration is complete.
    pub fn next(&mut self) -> PhpResult<()> {
        if self.done {
            return Ok(());
        }
        fetch_next(self)?;
        if !self.done {
            self.fetched = true;
            self.row += 1;
        }
        Ok(())
    }

    /// Rewind to the first row. The file is re-opened from the start.
    pub fn rewind(&mut self) -> PhpResult<()> {
        let file = File::open(&self.path)
            .map_err(|e| format!("Failed to open XML file '{}': {}", self.path, e))?;
        let mut reader = Reader::from_reader(BufReader::new(file));
        reader.config_mut().trim_text(true);
        self.reader = Some(reader);
        self.current = None;
        self.row = 0;
        self.done = false;
        self.fetched = false;
        Ok(())
    }

    /// Whether the current position is valid. Lazily reads the first row
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
    pub fn next_row(&mut self) -> PhpResult<Option<Zval>> {
        self.next()?;
        Ok(self.current())
    }

    /// Read up to `$count` rows in a single call, amortizing the per-call
    /// overhead across the whole batch.
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
}

/// Scan forward to the next row element and materialize it as `current`.
fn fetch_next(s: &mut XmlStreamer) -> PhpResult<()> {
    let Some(reader) = &mut s.reader else {
        s.done = true;
        return Ok(());
    };
    let row_tag = s.row_tag.clone();
    let mut buf = Vec::new();
    loop {
        match reader.read_event_into(&mut buf).map_err(|e| xml_err(reader, e))? {
            Event::Start(e) => {
                if local_name(e.name()) == row_tag.as_bytes() {
                    let attrs = build_attrs(&e)?;
                    let value = parse_element(reader, s.typed, 0, e.name(), attrs, false, true)?;
                    s.current = Some(elem_to_row(value));
                    s.done = false;
                    return Ok(());
                }
            }
            Event::Empty(e) => {
                if local_name(e.name()) == row_tag.as_bytes() {
                    let attrs = build_attrs(&e)?;
                    let value = parse_element(reader, s.typed, 0, e.name(), attrs, true, true)?;
                    s.current = Some(elem_to_row(value));
                    s.done = false;
                    return Ok(());
                }
            }
            Event::Eof => {
                s.done = true;
                return Ok(());
            }
            _ => {}
        }
    }
}

/// force_assoc guarantees an Array; wrap the impossible Scalar defensively.
fn elem_to_row(value: ElemValue) -> ZBox<ZendHashTable> {
    match value {
        ElemValue::Array(ht) => ht,
        ElemValue::Scalar(zv) => {
            let mut ht = ZendHashTable::with_capacity(1);
            let _ = ht.insert("@value", zv);
            ht
        }
    }
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module.class::<XmlStreamer>()
}
