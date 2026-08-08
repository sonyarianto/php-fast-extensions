use std::fs::File;
use std::io::{ErrorKind, Read};

use ext_php_rs::boxed::ZBox;
use ext_php_rs::prelude::*;
use ext_php_rs::types::{ZendHashTable, Zval};
use ext_php_rs::zend::ce;
use serde_json::Value;

/// Bytes read from the file per fill call.
const READ_CHUNK: usize = 64 * 1024;

/// Maximum nesting depth accepted inside a single JSON element.
const MAX_DEPTH: usize = 512;

/// Chunked byte source: reads the file in 64 KB blocks and serves one byte
/// at a time, so a top-level array can be scanned for element boundaries
/// without ever holding the whole file in memory.
struct ChunkReader<R: Read> {
    inner: R,
    buf: [u8; READ_CHUNK],
    len: usize,
    pos: usize,
    eof: bool,
}

impl<R: Read> ChunkReader<R> {
    fn new(inner: R) -> Self {
        Self {
            inner,
            buf: [0u8; READ_CHUNK],
            len: 0,
            pos: 0,
            eof: false,
        }
    }

    fn next_byte(&mut self) -> std::io::Result<Option<u8>> {
        if self.pos >= self.len {
            if self.eof {
                return Ok(None);
            }
            self.len = self.inner.read(&mut self.buf)?;
            self.pos = 0;
            if self.len == 0 {
                self.eof = true;
                return Ok(None);
            }
        }
        let b = self.buf[self.pos];
        self.pos += 1;
        Ok(Some(b))
    }
}

fn eof_err(msg: &str) -> std::io::Error {
    std::io::Error::new(ErrorKind::UnexpectedEof, msg.to_string())
}

fn skip_ws(r: &mut ChunkReader<File>) -> std::io::Result<Option<u8>> {
    loop {
        match r.next_byte()? {
            Some(b) if b.is_ascii_whitespace() => continue,
            other => return Ok(other),
        }
    }
}

/// Scan the next element of the top-level array, returning its raw bytes.
/// Returns None once the array is exhausted. Elements are parsed
/// independently by serde_json afterwards.
fn next_element(r: &mut ChunkReader<File>) -> std::io::Result<Option<Vec<u8>>> {
    let mut b = match skip_ws(r)? {
        Some(b) => b,
        None => return Err(eof_err("unexpected end of file inside top-level array")),
    };
    if b == b'[' {
        // consume the top-level array's opening bracket (first call only;
        // a '[' can never otherwise be the first byte of a call)
        b = skip_ws(r)?.ok_or_else(|| eof_err("unexpected end of file after '['"))?;
    }
    if b == b']' {
        return Ok(None); // end of the top-level array
    }
    if b == b',' {
        b = skip_ws(r)?.ok_or_else(|| eof_err("unexpected end of file after comma"))?;
    }

    let mut out = Vec::new();
    let mut depth: i64 = 0;
    let mut in_string = false;
    let mut escaped = false;
    let compound = b == b'{' || b == b'[';

    loop {
        if in_string {
            if escaped {
                escaped = false;
            } else if b == b'\\' {
                escaped = true;
            } else if b == b'"' {
                in_string = false;
            }
            out.push(b);
        } else {
            match b {
                b'"' => {
                    in_string = true;
                    out.push(b);
                }
                b'{' | b'[' => {
                    depth += 1;
                    out.push(b);
                }
                b'}' | b']' => {
                    if depth > 0 {
                        depth -= 1;
                        out.push(b);
                        if depth == 0 && compound {
                            // closing bracket of the compound element itself
                            return Ok(Some(out));
                        }
                    } else {
                        // scalar element terminated by the array's closing
                        // bracket; do not consume it
                        return Ok(Some(out));
                    }
                }
                b',' => {
                    if depth == 0 {
                        return Ok(Some(out)); // scalar element ended by comma
                    }
                    out.push(b);
                }
                _ => out.push(b),
            }
        }
        b = match r.next_byte()? {
            Some(b) => b,
            None => return Err(eof_err("unterminated element")),
        };
    }
}

fn value_to_zval(zv: &mut Zval, v: &Value, depth: usize) -> PhpResult<()> {
    if depth > MAX_DEPTH {
        return Err("JSON nesting too deep (>512)".into());
    }
    match v {
        Value::Null => {}
        Value::Bool(b) => zv.set_bool(*b),
        Value::Number(n) => {
            if n.is_f64() {
                // parsed from float syntax (e.g. 100.0, 1e3) — keep as float,
                // matching json_decode
                zv.set_double(n.as_f64().unwrap_or(0.0));
            } else if let Some(i) = n.as_i64() {
                zv.set_long(i);
            } else if let Some(u) = n.as_u64() {
                if u <= i64::MAX as u64 {
                    zv.set_long(u as i64);
                } else {
                    zv.set_double(u as f64);
                }
            } else {
                zv.set_double(n.as_f64().unwrap_or(0.0));
            }
        }
        Value::String(s) => zv.set_string(s.as_str(), false)?,
        Value::Array(arr) => {
            let mut ht = ZendHashTable::with_capacity(arr.len() as u32);
            for item in arr {
                let mut child = Zval::new();
                value_to_zval(&mut child, item, depth + 1)?;
                ht.push(child)?;
            }
            zv.set_hashtable(ht);
        }
        Value::Object(map) => {
            let mut ht = ZendHashTable::with_capacity(map.len() as u32);
            for (k, v) in map {
                let mut child = Zval::new();
                value_to_zval(&mut child, v, depth + 1)?;
                ht.insert(k.clone(), child)?;
            }
            zv.set_hashtable(ht);
        }
    }
    Ok(())
}

/// Convert a top-level element into the PHP array served as `current()`.
/// Objects become associative arrays, arrays become sequential lists, and
/// scalar elements become single-element lists.
fn value_to_ht(v: &Value, depth: usize) -> PhpResult<ZBox<ZendHashTable>> {
    match v {
        Value::Object(map) => {
            let mut ht = ZendHashTable::with_capacity(map.len() as u32);
            for (k, val) in map {
                let mut zv = Zval::new();
                value_to_zval(&mut zv, val, depth + 1)?;
                ht.insert(k.clone(), zv)?;
            }
            Ok(ht)
        }
        Value::Array(arr) => {
            let mut ht = ZendHashTable::with_capacity(arr.len() as u32);
            for val in arr {
                let mut zv = Zval::new();
                value_to_zval(&mut zv, val, depth + 1)?;
                ht.push(zv)?;
            }
            Ok(ht)
        }
        other => {
            let mut ht = ZendHashTable::with_capacity(1);
            let mut zv = Zval::new();
            value_to_zval(&mut zv, other, depth + 1)?;
            ht.push(zv)?;
            Ok(ht)
        }
    }
}

/// Streaming JSON reader for large top-level arrays.
///
/// Reads one element of a top-level JSON array at a time straight from
/// the file, so memory usage stays constant regardless of file size.
/// Implements PHP's `Iterator` interface.
#[php_class]
#[php(implements(ce = ce::iterator, stub = "\\Iterator"))]
pub struct JsonStreamer {
    path: String,
    reader: Option<ChunkReader<File>>,
    current: Option<ZBox<ZendHashTable>>,
    row: i64,
    done: bool,
    fetched: bool,
}

#[php_impl]
impl JsonStreamer {
    /// Open a JSON file for streaming.
    ///
    /// The file must contain a single top-level array; its elements are
    /// yielded one at a time as `current()`.
    ///
    /// @param string $path Path to the JSON file.
    /// @throws \Exception When the file cannot be opened.
    pub fn __construct(path: String) -> PhpResult<Self> {
        let file = File::open(&path)
            .map_err(|e| format!("Failed to open JSON file '{}': {}", path, e))?;
        Ok(Self {
            path,
            reader: Some(ChunkReader::new(file)),
            current: None,
            row: 0,
            done: false,
            fetched: false,
        })
    }

    /// Return the current element as an array, or null when iteration is
    /// done.
    ///
    /// Object elements become associative arrays, array elements become
    /// sequential lists, and scalar elements become single-element lists.
    /// Nested values keep their JSON types: numbers become int/float,
    /// booleans become bool, strings become string, null becomes null.
    ///
    /// @return array|null
    pub fn current(&mut self) -> Option<ZBox<ZendHashTable>> {
        if !self.fetched || self.done {
            return None;
        }
        self.current.clone()
    }

    /// Return the 0-based index of the current element.
    pub fn key(&self) -> i64 {
        self.row.saturating_sub(1).max(0)
    }

    /// Advance to the next element. Does nothing once iteration is complete.
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

    /// Rewind to the first element. The file is re-opened from the start.
    pub fn rewind(&mut self) -> PhpResult<()> {
        let file = File::open(&self.path)
            .map_err(|e| format!("Failed to open JSON file '{}': {}", self.path, e))?;
        self.reader = Some(ChunkReader::new(file));
        self.current = None;
        self.row = 0;
        self.done = false;
        self.fetched = false;
        Ok(())
    }

    /// Whether the current position is valid. Lazily reads the next element
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

    /// Advance to the next element and return it in a single call.
    ///
    /// @return array|null
    pub fn next_row(&mut self) -> PhpResult<Option<ZBox<ZendHashTable>>> {
        self.next()?;
        Ok(self.current())
    }

    /// Read up to `$count` elements in a single call, amortizing the
    /// per-call overhead across the whole batch.
    ///
    /// Returns an array of element arrays (fewer than `$count` at end of
    /// file), or null when no elements remain.
    ///
    /// @param int $count Maximum number of elements to read.
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

fn fetch_next(s: &mut JsonStreamer) -> PhpResult<()> {
    let Some(reader) = &mut s.reader else {
        s.done = true;
        return Ok(());
    };
    match next_element(reader) {
        Ok(Some(bytes)) => {
            let value: Value = serde_json::from_slice(&bytes).map_err(|e| {
                format!(
                    "Malformed JSON element at index {}: {} (near: {})",
                    s.row,
                    e,
                    String::from_utf8_lossy(&bytes).chars().take(64).collect::<String>()
                )
            })?;
            s.current = Some(value_to_ht(&value, 0)?);
            s.done = false;
        }
        Ok(None) => {
            s.done = true;
        }
        Err(e) => return Err(format!("Failed to read '{}': {}", s.path, e).into()),
    }
    Ok(())
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module.class::<JsonStreamer>()
}
