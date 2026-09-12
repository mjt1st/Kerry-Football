// Builds the plugin zip with FORWARD-SLASH entry names.
//
// PowerShell's Compress-Archive writes Windows backslashes as the path separator. The ZIP spec
// requires "/", so an unzipper reading one of those archives does not see directories at all --
// it sees files literally named "kerry-football-admin\includes\kf-notices.php". WordPress then
// finds no kerry-football-admin/kerry-football-admin.php and refuses to activate with
// "Plugin file does not exist." Versions 1.8.15 through 1.8.17 all shipped that way.
//
// Usage: node build-zip.js <plugin-root> <out.zip>
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const [, , ROOT, OUT] = process.argv;
if (fs.existsSync(OUT)) {
  console.error('ABORT: ' + OUT + ' already exists. Bump the version instead of overwriting.');
  process.exit(1);
}

// The shipped file list, matching every release up to 1.8.14: the main file, every include, the
// one stylesheet that is enqueued, and the two scripts. Not Vendor/, not the BACKUP file, not
// the unenqueued .min.css, not CLAUDE.md, not dist/.
const files = [
  'kerry-football-admin.php',
  ...fs.readdirSync(path.join(ROOT, 'includes')).filter(f => f.endsWith('.php')).sort().map(f => 'includes/' + f),
  'assets/css/kf-styles.css',
  'assets/js/kf-game-browser.js',
  'assets/js/kf-table-controls.js',
];

const CRC_TABLE = (() => {
  const t = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c;
  }
  return t;
})();
function crc32(buf) {
  let c = -1;
  for (let i = 0; i < buf.length; i++) c = CRC_TABLE[(c ^ buf[i]) & 0xFF] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
}

function dosTime(d) {
  return ((d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1)) & 0xFFFF;
}
function dosDate(d) {
  return (((d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate()) & 0xFFFF;
}

const chunks = [];
const central = [];
let offset = 0;

for (const rel of files) {
  const abs = path.join(ROOT, rel);
  const raw = fs.readFileSync(abs);
  const mtime = fs.statSync(abs).mtime;
  const name = Buffer.from('kerry-football-admin/' + rel, 'utf8'); // forward slashes, always
  const deflated = zlib.deflateRawSync(raw, { level: 9 });
  const useStore = deflated.length >= raw.length;
  const body = useStore ? raw : deflated;
  const method = useStore ? 0 : 8;
  const crc = crc32(raw);
  const t = dosTime(mtime), d = dosDate(mtime);

  const local = Buffer.alloc(30);
  local.writeUInt32LE(0x04034b50, 0);
  local.writeUInt16LE(20, 4);      // version needed
  local.writeUInt16LE(0x0800, 6);  // UTF-8 filename
  local.writeUInt16LE(method, 8);
  local.writeUInt16LE(t, 10);
  local.writeUInt16LE(d, 12);
  local.writeUInt32LE(crc, 14);
  local.writeUInt32LE(body.length, 18);
  local.writeUInt32LE(raw.length, 22);
  local.writeUInt16LE(name.length, 26);
  local.writeUInt16LE(0, 28);
  chunks.push(local, name, body);

  const cd = Buffer.alloc(46);
  cd.writeUInt32LE(0x02014b50, 0);
  cd.writeUInt16LE(20, 4);         // version made by: MS-DOS/FAT
  cd.writeUInt16LE(20, 6);
  cd.writeUInt16LE(0x0800, 8);
  cd.writeUInt16LE(method, 10);
  cd.writeUInt16LE(t, 12);
  cd.writeUInt16LE(d, 14);
  cd.writeUInt32LE(crc, 16);
  cd.writeUInt32LE(body.length, 20);
  cd.writeUInt32LE(raw.length, 24);
  cd.writeUInt16LE(name.length, 28);
  cd.writeUInt16LE(0, 30);         // extra
  cd.writeUInt16LE(0, 32);         // comment
  cd.writeUInt16LE(0, 34);         // disk
  cd.writeUInt16LE(0, 36);         // internal attrs
  // External attributes. "Version made by" above is 20 (MS-DOS/FAT), so this field is FAT
  // attributes, and 0 means an ordinary file. (Unix mode bits would need version-made-by 3,
  // and 0o100644 << 16 overflows a JS 32-bit shift into a negative anyway.)
  cd.writeUInt32LE(0, 38);
  cd.writeUInt32LE(offset, 42);
  central.push(cd, name);

  offset += local.length + name.length + body.length;
}

const cdBuf = Buffer.concat(central);
const eocd = Buffer.alloc(22);
eocd.writeUInt32LE(0x06054b50, 0);
eocd.writeUInt16LE(0, 4);
eocd.writeUInt16LE(0, 6);
eocd.writeUInt16LE(files.length, 8);
eocd.writeUInt16LE(files.length, 10);
eocd.writeUInt32LE(cdBuf.length, 12);
eocd.writeUInt32LE(offset, 16);
eocd.writeUInt16LE(0, 20);

fs.writeFileSync(OUT, Buffer.concat([...chunks, cdBuf, eocd]));
console.log('wrote ' + OUT + ' (' + files.length + ' files, ' + fs.statSync(OUT).size + ' bytes)');
