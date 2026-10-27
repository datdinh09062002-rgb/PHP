<?php
/*
 * index.php - MỘT FILE DUY NHẤT: PHP (lấy dữ liệu) + HTML/CSS/JS (giao diện).
 * Chạy:  php -S localhost:8000     (trong thư mục chứa file này)  rồi mở http://localhost:8000
 * Hoặc đặt vào thư mục htdocs của XAMPP / hosting có PHP 7.4+ và bật extension curl, mbstring.
 */
const VND_HOSTS = ['https://api-finfo.vndirect.com.vn', 'https://finfo-api.vndirect.com.vn'];
const PY_SERVER = '';      // tùy chọn: 'http://127.0.0.1:3000' nếu bạn đang chạy server.py (vnstock) - sẽ ưu tiên dùng nguồn này
const CACHE_TTL = 3600;    // giây
const VERIFY_SSL = true;   // nếu gặp lỗi "SSL certificate problem" trên Windows, đổi thành false

$RATIO = ['PE' => 'PRICE_TO_EARNINGS', 'PB' => 'PRICE_TO_BOOK', 'PS' => 'PRICE_TO_SALES', 'EPS' => 'EPS_TR', 'BVPS' => 'BVPS_CR'];
$ITEMS = [
  'cash' => ['tien va tuong duong tien', 'cash and cash equivalents'],
  'recv' => ['cac khoan phai thu ngan han', 'phai thu ngan han', 'short-term receivables'],
  'inv' => ['hang ton kho, rong', 'hang ton kho', 'inventories'],
  'fixed' => ['tai san co dinh', 'fixed assets'],
  'wip' => ['tai san do dang dai han', 'xay dung co ban do dang', 'long-term assets in progress'],
  'ta' => ['tong cong tai san', 'total assets'],
  'stdebt' => ['vay va no thue tai chinh ngan han', 'vay ngan han', 'short-term borrowings'],
  'ltdebt' => ['vay va no thue tai chinh dai han', 'vay dai han', 'long-term borrowings'],
  'prepaid' => ['nguoi mua tra tien truoc', 'customer advances'],
  'equity' => ['von chu so huu', "owners' equity", "owner's equity"],
  'rev' => ['doanh thu thuan', 'net revenue'],
  'cogs' => ['gia von hang ban', 'cost of goods sold'],
  'gp' => ['loi nhuan gop', 'gross profit'],
  'npp' => ['loi nhuan sau thue cong ty me', 'loi nhuan sau thue cua co dong cong ty me', 'parent'],
  'np' => ['loi nhuan sau thue', 'net profit after tax', 'net profit'],
  'cfo' => ['luu chuyen tien thuan tu hoat dong kinh doanh', 'operating activities'],
  'cfi' => ['luu chuyen tien thuan tu hoat dong dau tu', 'investing activities'],
  'cff' => ['luu chuyen tien thuan tu hoat dong tai chinh', 'financing activities'],
  'netcf' => ['luu chuyen tien thuan trong ky', 'net cash flow'],
  'div' => ['co tuc, loi nhuan da tra', 'co tuc da tra', 'tra co tuc', 'dividends paid', 'co tuc'],
];

function norm(string $s): string {            // bỏ dấu tiếng Việt, chữ thường (không cần extension intl)
  $s = mb_strtolower($s, 'UTF-8');
  $map = ['a' => 'áàảãạăắằẳẵặâấầẩẫậ', 'e' => 'éèẻẽẹêếềểễệ', 'i' => 'íìỉĩị', 'o' => 'óòỏõọôốồổỗộơớờởỡợ',
          'u' => 'úùủũụưứừửữự', 'y' => 'ýỳỷỹỵ', 'd' => 'đ'];
  foreach ($map as $k => $chars) foreach (mb_str_split($chars) as $c) $s = str_replace($c, $k, $s);
  return $s;
}
function http_get(string $url): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_FOLLOWLOCATION => true, CURLOPT_ENCODING => '', CURLOPT_SSL_VERIFYPEER => VERIFY_SSL, CURLOPT_SSL_VERIFYHOST => VERIFY_SSL ? 2 : 0,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
    CURLOPT_HTTPHEADER => ['Accept: application/json, text/plain, */*', 'Origin: https://dstock.vndirect.com.vn', 'Referer: https://dstock.vndirect.com.vn/']]);
  $body = curl_exec($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  return [$st, (string)$body, $err];
}
function vnd(array $paths, array &$errors, string $label): ?array {   // thử nhiều đường dẫn x nhiều tên miền
  foreach ($paths as $p) foreach (VND_HOSTS as $h) {
    [$st, $body, $err] = http_get($h . $p);
    if ($st === 200) {
      $j = json_decode($body, true);
      $rows = is_array($j) ? ($j['data'] ?? ($j['content'] ?? (isset($j[0]) ? $j : null))) : null;
      if (is_array($rows)) return $rows;
      $errors[] = "$label: phản hồi không đúng định dạng"; continue;
    }
    $errors[] = "$label: $h → " . ($err ?: "HTTP $st " . trim(substr(strip_tags($body), 0, 80)));
  }
  return null;
}
function sorted_series(?array $rows): array {
  $out = [];
  foreach ($rows ?? [] as $r) if (isset($r['value'], $r['reportDate']) && $r['value'] !== null) $out[] = ['d' => $r['reportDate'], 'v' => (float)$r['value']];
  usort($out, fn($a, $b) => strcmp($a['d'], $b['d']));
  return $out;
}
function build_vnd(string $sym): array {
  global $RATIO, $ITEMS;
  $e = []; $debug = [];
  $p = vnd(["/v4/company_profiles?q=code:$sym"], $e, 'hồ sơ'); $d = $p[0] ?? [];
  $prof = array_filter(['name' => $d['companyName'] ?? $d['shortName'] ?? null, 'eng' => $d['companyNameEng'] ?? null,
    'industry' => $d['industryName'] ?? $d['icbName'] ?? null, 'shares' => $d['issueShare'] ?? null,
    'exchange' => $d['floor'] ?? null, 'listed' => $d['listedDate'] ?? null], fn($v) => $v !== null);
  $prof['name'] = $prof['name'] ?? $sym;

  $price = null; $from = date('Y-m-d', strtotime('-1 year'));
  $r = vnd(["/v4/stock_prices?sort=date&q=code:$sym~date:gte:$from&size=400&page=1"], $e, 'giá');
  if ($r && count($r) > 1) {
    usort($r, fn($a, $b) => strcmp($a['date'], $b['date']));
    $l = end($r); $pv = $r[count($r) - 2];
    $c = (float)($l['close'] ?? $l['adClose']); $pc = (float)($pv['close'] ?? $pv['adClose']);
    $price = ['last' => round($c, 2), 'change' => $pc ? round(($c / $pc - 1) * 100, 2) : null];
  }
  $series = [];
  foreach ($RATIO as $key => $code) {
    $q = "q=code:$sym~ratioCode:$code~reportDate:gte:2009-12-31";
    $series[$key] = sorted_series(vnd(["/v4/ratios?$q&sort=reportDate:desc&size=5000&page=1", "/v4/ratios?$q&sort=reportDate&size=5000&page=1", "/v4/ratios?$q&size=3000&page=1"], $e, "chỉ số $key"));
  }
  $st = ['years' => [], 'items' => []];
  $q = "q=code:$sym~reportType:ANNUAL~fiscalDate:gte:" . date('Y-m-d', strtotime('-16 years'));
  $rows = vnd(["/v4/financial_statements?$q&sort=fiscalDate&size=10000&page=1", "/v4/financial_statements?$q&size=3000&page=1", "/v4/financial_statements?$q~modelType:1,2,3&size=3000&page=1"], $e, 'BCTC');
  if ($rows) {
    $names = [];
    foreach ($rows as $r) { $c = (string)($r['itemCode'] ?? ''); if ($c !== '' && !isset($names[$c]))
      $names[$c] = ['raw' => $r['itemName'] ?? ($r['itemNameEn'] ?? ''), 'n' => [norm($r['itemName'] ?? ''), norm($r['itemNameEn'] ?? '')]]; }
    $map = [];
    foreach ($ITEMS as $k => $kws) foreach ($kws as $kw) {
      $hits = [];
      foreach ($names as $c => $v) foreach ($v['n'] as $n) if ($n !== '' && strpos($n, $kw) !== false) { $hits[$c] = mb_strlen($v['raw']); break; }
      if ($hits) { asort($hits); $map[$k] = (string)array_key_first($hits); break; }
    }
    $inv = array_flip($map); $T = [];
    foreach ($rows as $r) { $k = $inv[(string)($r['itemCode'] ?? '')] ?? null;
      if ($k && isset($r['numericValue']) && isset($r['fiscalDate'])) $T[substr($r['fiscalDate'], 0, 4)][$k] = (float)$r['numericValue']; }
    ksort($T); $years = array_keys($T);
    foreach (array_keys($ITEMS) as $k) if (isset($map[$k]))
      $st['items'][$k] = array_map(fn($y) => $T[$y][$k] ?? null, $years);
    $st['years'] = array_map('strval', $years);
    $debug['map'] = array_map(fn($c) => $c . ': ' . $names[$c]['raw'], $map);
    $debug['all_items'] = array_map(fn($c, $v) => "$c: " . $v['raw'], array_keys($names), $names);
  }
  return ['symbol' => $sym, 'profile' => $prof, 'price' => $price, 'series' => $series, 'statements' => $st,
          'errors' => array_values(array_unique($e)), 'debug' => $debug, 'updated' => date('d/m/Y H:i')];
}

if (($_GET['api'] ?? '') === 'data') {
  header('Content-Type: application/json; charset=utf-8');
  set_time_limit(180);
  $sym = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $_GET['symbol'] ?? 'HPG')) ?: 'HPG';
  if (!function_exists('curl_init') || !function_exists('mb_str_split')) {
    echo json_encode(['symbol' => $sym, 'errors' => ['Thiếu extension curl/mbstring hoặc PHP < 7.4. Hãy bật curl, mbstring trong php.ini.'],
      'profile' => ['name' => $sym], 'series' => [], 'statements' => ['years' => [], 'items' => []], 'debug' => []]); exit;
  }
  $cf = sys_get_temp_dir() . "/stockdash_$sym.json";
  if (is_file($cf) && time() - filemtime($cf) < CACHE_TTL) { readfile($cf); exit; }
  $data = null; $pyerr = [];
  if (PY_SERVER) {
    [$s, $b, $er] = http_get(rtrim(PY_SERVER, '/') . "/api/data?symbol=$sym");
    $j = $s === 200 ? json_decode($b, true) : null;
    if (is_array($j) && (!empty($j['price']) || !empty($j['statements']['years']))) $data = $j;
    else $pyerr[] = 'Máy chủ Python (vnstock) không trả dữ liệu: ' . ($er ?: "HTTP $s") . ' → chuyển sang VNDirect';
  }
  if (!$data) { $data = build_vnd($sym); $data['errors'] = array_merge($pyerr, $data['errors']); }
  $out = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
  if (empty($data['errors'])) @file_put_contents($cf, $out);
  echo $out; exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Phân tích doanh nghiệp</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
:root{--blue:#0f4a8a;--blue2:#1d6fc4;--orange:#f97316;--bg:#eef2f7;--card:#fff;--text:#1e293b;--muted:#64748b;--line:#e8edf3;
  --up:#16a34a;--down:#dc2626;--flat:#ca8a04;--shadow:0 1px 2px rgba(15,23,42,.06),0 4px 14px rgba(15,23,42,.06)}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{margin:0;font-family:Inter,"Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;background:var(--bg);color:var(--text);
  -webkit-font-smoothing:antialiased;font-variant-numeric:tabular-nums}
/* ===== Thanh đầu trang ===== */
header{position:sticky;top:0;z-index:20;background:linear-gradient(135deg,#0b3a70 0%,var(--blue) 55%,var(--blue2) 100%);color:#fff;
  padding:12px 28px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;box-shadow:0 2px 12px rgba(11,58,112,.35)}
header h1{margin:0;font-size:20px;font-weight:700;letter-spacing:.2px;flex:1;display:flex;align-items:center;gap:10px}
header h1::before{content:"";width:10px;height:22px;border-radius:3px;background:var(--orange)}
header input{padding:9px 16px;border-radius:999px;border:2px solid transparent;width:130px;text-transform:uppercase;font-weight:700;
  letter-spacing:1px;text-align:center;background:rgba(255,255,255,.95);color:var(--blue);outline:none;transition:.15s}
header input:focus{border-color:var(--orange);box-shadow:0 0 0 4px rgba(249,115,22,.25)}
header button{padding:9px 20px;border:0;border-radius:999px;background:var(--orange);color:#fff;font-weight:700;cursor:pointer;
  box-shadow:0 2px 8px rgba(249,115,22,.4);transition:transform .12s,box-shadow .12s,filter .12s}
header button:hover{transform:translateY(-1px);filter:brightness(1.07);box-shadow:0 4px 14px rgba(249,115,22,.5)}
header button:active{transform:translateY(0)}
header button#diag{background:rgba(255,255,255,.16)!important;box-shadow:none;border:1px solid rgba(255,255,255,.35)}
/* ===== Khung chính ===== */
main{max-width:1360px;margin:0 auto;padding:22px 20px 10px}
.card{background:var(--card);border-radius:14px;padding:18px;box-shadow:var(--shadow);border:1px solid var(--line);transition:box-shadow .2s,transform .2s}
.grid .card:hover,.val .card:hover{box-shadow:0 8px 24px rgba(15,23,42,.12);transform:translateY(-2px)}
.top{display:grid;grid-template-columns:1.15fr 1fr 1fr;gap:18px;margin-bottom:18px}
@media(max-width:960px){.top{grid-template-columns:1fr}header{padding:12px 16px}main{padding:16px 12px}}
/* ===== Thẻ giá ===== */
.top>.card:first-child{background:linear-gradient(160deg,#fff 0%,#f4f8ff 100%);border-left:5px solid var(--blue)}
.top h2{margin:0;font-size:24px;font-weight:800;color:var(--blue);line-height:1.25}
.sub{font-size:13px;color:var(--muted);margin:4px 0}
.px{font-size:46px;font-weight:800;line-height:1.1;margin-top:10px;letter-spacing:-1px}
#chg{display:inline-block;margin-top:6px;padding:3px 12px;border-radius:999px;font-weight:700;font-size:14px;background:#f1f5f9}
#chg:empty{display:none}
#chg.up{background:#dcfce7}#chg.down{background:#fee2e2}
.up{color:var(--up)}.down{color:var(--down)}.flat{color:var(--flat)}
/* ===== Góc nhìn + thống kê ===== */
.view{padding:0;overflow:hidden}
.view h4{margin:0;background:linear-gradient(135deg,var(--blue),var(--blue2));color:#fff;text-align:center;padding:12px;font-size:15px;
  letter-spacing:.4px;text-transform:uppercase}
.view #view{padding:6px 18px 12px}
.row{display:flex;justify-content:space-between;align-items:center;padding:10px 2px;border-bottom:1px solid var(--line);font-size:14px}
.row:last-child{border-bottom:0}
.row span{color:var(--muted)}.row b{font-weight:700}
.view .row b.up,.view .row b.down,.view .row b.flat{padding:2px 10px;border-radius:999px;font-size:13px}
.view .row b.up{background:#dcfce7}.view .row b.down{background:#fee2e2}.view .row b.flat{background:#fef9c3}
#stats .row:nth-child(odd){background:#f8fafc;margin:0 -8px;padding:10px 10px;border-radius:6px}
/* ===== Biểu đồ ===== */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(400px,1fr));gap:18px}
@media(max-width:480px){.grid{grid-template-columns:1fr}}
.card h3{margin:0 0 12px;font-size:14px;font-weight:700;color:var(--blue);text-transform:uppercase;letter-spacing:.3px;
  padding-bottom:10px;border-bottom:2px solid var(--line);line-height:1.4}
.card h3 span{text-transform:none;font-weight:700;letter-spacing:0}
.card canvas{max-height:330px}
.err{font-size:13px;color:var(--down);background:#fef2f2;padding:8px 12px;border-radius:8px;margin-top:6px}
/* ===== Định giá ===== */
.val{margin-top:22px}
.val .bar{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px;padding:12px 16px;background:var(--card);
  border-radius:12px;box-shadow:var(--shadow);border:1px solid var(--line);font-size:14px;font-weight:600;color:var(--blue)}
.val input[type=date]{padding:7px 10px;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;color:var(--text);outline:none;transition:.15s}
.val input[type=date]:focus{border-color:var(--blue2);box-shadow:0 0 0 3px rgba(29,111,196,.18)}
.val h3{text-transform:none;text-align:center;font-size:17px;border-bottom:0;padding-bottom:4px}
/* ===== Khung lỗi / chẩn đoán ===== */
#log,details pre{font:12px/1.7 Consolas,"Cascadia Code",monospace;color:#475569;white-space:pre-wrap}
#log:not(:empty){background:#fef2f2;border:1px solid #fecaca;border-left:5px solid var(--down);padding:12px 16px;border-radius:10px;
  margin-bottom:18px;color:#991b1b}
details{margin-top:18px;background:var(--card);padding:14px 18px;border-radius:12px;box-shadow:var(--shadow);border:1px solid var(--line)}
details summary{cursor:pointer;font-weight:600;color:var(--blue)}
details pre{max-height:360px;overflow:auto;background:#f8fafc;padding:12px;border-radius:8px}
#diagout{border:1px solid #cbd5e1;border-radius:10px;padding:12px;background:#f8fafc}
footer{text-align:center;color:var(--muted);font-size:12px;padding:26px 16px 32px}
::-webkit-scrollbar{width:10px;height:10px}::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:8px}
</style>
</head>
<body>
<header>
  <h1>Phân tích doanh nghiệp</h1>
  <input id="sym" value="HPG" maxlength="5">
  <button id="go">Xem</button>
  <button id="diag" style="background:#475569">Chẩn đoán</button>
</header>
<main>
  <div id="log"></div>
  <div class="top">
    <div class="card"><h2 id="name">-</h2><div class="sub" id="eng"></div><div class="sub" id="ind"></div>
      <div class="px" id="px">-</div><div id="chg"></div></div>
    <div class="card view"><h4>Góc nhìn định giá</h4><div id="view"></div></div>
    <div class="card" id="stats"></div>
  </div>
  <div class="grid" id="grid"></div>
  <div class="val" id="val"></div>
  <textarea id="diagout" rows="16" style="display:none;width:100%;margin-top:16px;font:12px Consolas,monospace"></textarea>
  <details><summary>Danh sách chỉ tiêu báo cáo tài chính (dùng để sửa ánh xạ khi biểu đồ trống)</summary><pre id="items"></pre></details>
</main>
<footer>Dữ liệu qua máy chủ PHP (VNDirect, tùy chọn vnstock) • Dành cho doanh nghiệp phi tài chính (không dùng cho ngân hàng, chứng khoán, bảo hiểm) • Chỉ mang tính tham khảo.</footer>

<script>
/* ================= CẤU HÌNH ================= */
const BASES = ["https://api-finfo.vndirect.com.vn", "/api", "http://localhost:3000/api"];
const RATIO_CODES = { PE: "PRICE_TO_EARNINGS", PB: "PRICE_TO_BOOK", PS: "PRICE_TO_SALES",
                      EPS: "EPS_TR", BVPS: "BVPS_CR" };
const YEARS_BACK = 16, MAX_POINTS = 600, VAL_CAP = 100;   // VAL_CAP: loại giá trị định giá quá lớn (nhiễu)
// Từ khóa (không dấu, chữ thường) để dò chỉ tiêu theo tên; thử theo thứ tự ưu tiên.
const ITEMS = {
  cash:["tien va tuong duong tien","cash and cash equivalents"],
  recv:["cac khoan phai thu ngan han","phai thu ngan han","short-term receivables"],
  inv:["hang ton kho, rong","hang ton kho","inventories"],
  fixed:["tai san co dinh","fixed assets"],
  wip:["tai san do dang dai han","long-term assets in progress","xay dung co ban do dang"],
  ta:["tong cong tai san","total assets"],
  stdebt:["vay va no thue tai chinh ngan han","vay ngan han","short-term borrowings"],
  ltdebt:["vay va no thue tai chinh dai han","vay dai han","long-term borrowings"],
  prepaid:["nguoi mua tra tien truoc","customer advances"],
  equity:["von chu so huu","owners' equity","owner's equity"],
  rev:["doanh thu thuan","net revenue"],
  cogs:["gia von hang ban","cost of goods sold"],
  gp:["loi nhuan gop","gross profit"],
  npp:["loi nhuan sau thue cong ty me","loi nhuan sau thue cua co dong cong ty me","parent","attributable to parent"],
  np:["loi nhuan sau thue","net profit after tax","net profit"],
  cfo:["luu chuyen tien thuan tu hoat dong kinh doanh","operating activities"],
  cfi:["luu chuyen tien thuan tu hoat dong dau tu","investing activities"],
  cff:["luu chuyen tien thuan tu hoat dong tai chinh","financing activities"],
  netcf:["luu chuyen tien thuan trong ky","net cash flow"],
  div:["co tuc, loi nhuan da tra","co tuc da tra","tra co tuc","dividends paid","co tuc"],
};
/* ============================================ */

const $ = id => document.getElementById(id);
if (typeof Chart === "undefined") document.body.insertAdjacentHTML("afterbegin",
  '<div style="background:#fee2e2;color:#991b1b;padding:12px;font-weight:600">Không tải được thư viện Chart.js (cần Internet, hoặc mạng đang chặn cdn.jsdelivr.net). Biểu đồ sẽ không hiển thị.</div>');
const COL = ["#f59e0b","#3b82f6","#9ca3af","#22c55e","#ef4444","#8b5cf6","#111827"];
let base = null, charts = [], valCharts = {}, valRows = {}, logs = [];
const log = m => { logs.push(m); $("log").textContent = logs.join("\n"); };

async function api(p) {
  const errs = [];
  for (const b of (base ? [base] : BASES)) {
    try {
      const r = await fetch(b + p);
      if (!r.ok) { const t = (await r.text()).slice(0, 120); throw new Error("HTTP " + r.status + " " + t); }
      let j = await r.json();
      if (Array.isArray(j)) j = { data: j }; else if (!j.data && j.content) j.data = j.content;
      if (!base) log("Kết nối qua: " + (b || "(cùng máy chủ)"));
      base = b; return j;
    } catch (e) { errs.push((b || "(cùng máy chủ)") + " → " + e.message); }
  }
  throw new Error(errs.join(" | "));
}
async function apiAny(paths) { let e0; for (const p of paths) { try { return await api(p); } catch (e) { e0 = e; } } throw e0; }
const iso = y => { const d = new Date(); d.setFullYear(d.getFullYear() - y); return d.toISOString().slice(0, 10); };
const norm = s => (s || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/đ/g, "d").replace(/Đ/g, "D").toLowerCase();
const fmt = (v, d = 2) => v == null || isNaN(v) ? "-" : Number(v).toLocaleString("en-US", { maximumFractionDigits: d });
const median = a => { const s = [...a].sort((x, y) => x - y), m = s.length >> 1; return s.length % 2 ? s[m] : (s[m - 1] + s[m]) / 2; };
const mean = a => a.reduce((x, y) => x + y, 0) / a.length;
const sd = a => { const m = mean(a); return Math.sqrt(mean(a.map(v => (v - m) ** 2))); };
const ds = (rows, n = MAX_POINTS) => { const st = Math.max(1, Math.ceil(rows.length / n)); return rows.filter((_, i) => i % st === 0 || i === rows.length - 1); };
const cls = p => p < -5 ? "up" : p > 5 ? "down" : "flat";   // rẻ hơn trung vị = xanh

function addCard(title, parent = $("grid")) {
  const c = document.createElement("div"); c.className = "card";
  c.innerHTML = `<h3>${title}</h3><canvas></canvas>`; parent.appendChild(c); return c;
}
function chart(card, labels, sets, stacked = false) {
  const two = sets.some(s => s.y === "y1");
  const ch = new Chart(card.querySelector("canvas"), {
    data: { labels, datasets: sets.map((s, i) => ({
      type: s.type, label: s.label, data: s.data, yAxisID: s.y || "y",
      borderColor: s.color || COL[i % 7], backgroundColor: s.color || COL[i % 7],
      stack: s.type === "bar" && stacked ? "s" : undefined, order: s.type === "line" ? 0 : 1,
      borderWidth: s.dash ? 1.2 : 2, borderDash: s.dash ? [5, 4] : [], pointRadius: 0, tension: .2, spanGaps: true })) },
    options: { responsive: true, interaction: { mode: "index", intersect: false },
      plugins: { legend: { position: "bottom", labels: { boxWidth: 12 } },
                 tooltip: { callbacks: { label: c => `${c.dataset.label}: ${fmt(c.parsed.y, 2)}` } } },
      scales: { x: { stacked, ticks: { maxTicksLimit: 12, maxRotation: 60 } },
                y: { stacked },
                y1: { display: two, position: "right", grid: { drawOnChartArea: false } } } }
  });
  charts.push(ch); return ch;
}

/* ---------- dữ liệu từ máy chủ Python (server.py + vnstock) ---------- */
let SRV = {};
async function getServerData(sym) {
  const r = await fetch(`?api=data&symbol=${sym}`);
  if (!r.ok) throw new Error("máy chủ trả HTTP " + r.status);
  return r.json();
}
async function loadHeader(sym) {
  const p = SRV.profile || {};
  $("name").textContent = `${p.name || sym} (${p.exchange || "HOSE"}: ${sym})`;
  $("eng").textContent = p.eng || ""; $("ind").textContent = p.industry || "";
  return { issueShare: p.shares, listedDate: p.listed };
}
async function loadPrice() {
  const p = SRV.price; if (!p) return null;
  $("px").textContent = fmt(p.last);
  if (p.change != null) { $("chg").textContent = `${p.change >= 0 ? "+" : ""}${p.change.toFixed(2)}%`; $("chg").className = p.change >= 0 ? "up" : "down"; }
  return p.last;
}
async function loadRatio(sym, code, from) {
  const key = Object.keys(RATIO_CODES).find(k => RATIO_CODES[k] === code);
  return ((SRV.series || {})[key] || []).filter(r => r.d >= from);
}
async function loadStatements() {
  const S = SRV.statements;
  if (!S || !S.years || !S.years.length) throw new Error("không có dữ liệu báo cáo tài chính (xem khung lỗi phía trên hoặc bấm Chẩn đoán)");
  const T = {};
  S.years.forEach((y, i) => { T[y] = {}; for (const [k, arr] of Object.entries(S.items)) if (arr[i] != null) T[y][k] = arr[i]; });
  const map = (SRV.debug || {}).map || {};
  $("items").textContent = "ÁNH XẠ CỘT ĐÃ DÒ:\n" + Object.keys(ITEMS).map(k => `${k.padEnd(8)} -> ${map[k] || "(KHÔNG TÌM THẤY)"}`).join("\n") +
    "\n\nDEBUG:\n" + JSON.stringify(SRV.debug, null, 1);
  return { T, years: S.years, missing: Object.keys(ITEMS).filter(k => !S.items[k]) };
}

function renderStatements({ T, years }) {
  const bn = v => v == null ? null : +(v / 1e9).toFixed(1);
  const A = k => years.map(y => T[y][k] ?? null);
  const B = k => A(k).map(bn);
  const pct = (a, b) => a.map((v, i) => v != null && b[i] ? +(v / b[i] * 100).toFixed(2) : null);
  const gr = a => a.map((v, i) => i && v != null && a[i - 1] ? +((v - a[i - 1]) / Math.abs(a[i - 1]) * 100).toFixed(1) : null);
  const cagr = a => { const n = a.length - 1, f = a[n - 5], l = a[n]; return f > 0 && l > 0 ? ((l / f) ** (1 / 5) - 1) * 100 : null; };
  const tag = (t, c) => c == null ? t : `${t}: <span class="${c >= 0 ? "up" : "down"}">tăng trưởng ${fmt(c, 0)}% kép/năm trong 5 năm gần nhất</span>`;
  const ta = A("ta"), eq = A("equity"), np = A("np"), npp = A("npp").map((v, i) => v ?? np[i]), rev = A("rev");
  const oth = (keys) => ta.map((t, i) => t == null ? null : bn(t - keys.reduce((s, k) => s + (A(k)[i] ?? 0), 0)));
  const L = years;
  const draw = (title, fn) => { const c = addCard(title); try { fn(c); } catch (e) { c.innerHTML += `<div class="err">${e.message}</div>`; } };

  draw("Tài sản (tỷ đồng)", c => chart(c, L, [
    { type: "bar", label: "Tiền & tương đương tiền", data: B("cash") }, { type: "bar", label: "Các khoản phải thu", data: B("recv") },
    { type: "bar", label: "Hàng tồn kho", data: B("inv") }, { type: "bar", label: "Tài sản cố định", data: B("fixed") },
    { type: "bar", label: "TS dở dang dài hạn", data: B("wip") }, { type: "bar", label: "Khác", data: oth(["cash", "recv", "inv", "fixed", "wip"]) },
    { type: "line", label: "Khoản phải thu/TS (%)", data: pct(A("recv"), ta), y: "y1", color: "#111827" }], true));

  draw("Nguồn vốn (tỷ đồng)", c => chart(c, L, [
    { type: "bar", label: "Vốn chủ sở hữu", data: B("equity") }, { type: "bar", label: "Vay ngắn hạn", data: B("stdebt") },
    { type: "bar", label: "Vay dài hạn + TP chuyển đổi", data: B("ltdebt") }, { type: "bar", label: "Người mua trả trước", data: B("prepaid") },
    { type: "bar", label: "Khác", data: oth(["equity", "stdebt", "ltdebt", "prepaid"]) },
    { type: "line", label: "Nợ vay ròng/VCSH (%)", y: "y1", color: "#111827",
      data: ta.map((_, i) => eq[i] ? +(((A("stdebt")[i] ?? 0) + (A("ltdebt")[i] ?? 0) - (A("cash")[i] ?? 0)) / eq[i] * 100).toFixed(1) : null) }], true));

  draw("ROE và ROA (cuối kỳ, %)", c => chart(c, L, [
    { type: "line", label: "ROE cuối kỳ (%)", data: pct(npp, eq), color: "#f97316" },
    { type: "line", label: "ROA cuối kỳ (%)", data: pct(np, ta), color: "#9ca3af" }]));

  draw(tag("Doanh thu thuần (tỷ đồng)", cagr(rev)), c => chart(c, L, [
    { type: "bar", label: "Doanh số", data: B("rev"), color: "#c7c9cc" },
    { type: "line", label: "Tăng trưởng DT thuần YoY (%)", data: gr(rev), y: "y1", color: "#f97316" }]));

  draw(tag("LNST cổ đông công ty mẹ (tỷ đồng)", cagr(npp)), c => chart(c, L, [
    { type: "bar", label: "LNST của cổ đông công ty mẹ", data: npp.map(bn), color: "#c7c9cc" },
    { type: "line", label: "Tăng trưởng LNST YoY (%)", data: gr(npp), y: "y1", color: "#f97316" }]));

  draw("Biên LNG và biên LNR (%)", c => chart(c, L, [
    { type: "line", label: "Biên lợi nhuận gộp (%)", data: pct(A("gp"), rev), color: "#f97316" },
    { type: "line", label: "Biên lợi nhuận ròng (%)", data: pct(np, rev), color: "#9ca3af" }]));

  draw("Lưu chuyển tiền tệ (tỷ đồng)", c => chart(c, L, [
    { type: "bar", label: "LCTT hoạt động kinh doanh", data: B("cfo"), color: "#34d399" },
    { type: "bar", label: "LCTT hoạt động đầu tư", data: B("cfi"), color: "#fbbf24" },
    { type: "bar", label: "LCTT hoạt động tài chính", data: B("cff"), color: "#f87171" },
    { type: "line", label: "Lưu chuyển tiền thuần trong kỳ", data: B("netcf"), color: "#111827" }]));

  draw("Vòng quay hàng tồn kho (lần)", c => { const inv = A("inv"), cg = A("cogs");
    chart(c, L, [
      { type: "bar", label: "Hàng tồn kho (tỷ đồng)", data: B("inv"), color: "#c7c9cc" },
      { type: "line", label: "Vòng quay hàng tồn kho (lần)", y: "y1", color: "#111827",
        data: inv.map((v, i) => { const avg = i && inv[i - 1] != null ? (v + inv[i - 1]) / 2 : v;
          return v != null && cg[i] != null && avg ? +(Math.abs(cg[i]) / avg).toFixed(2) : null; }) }]); });

  draw("Cổ tức (tỷ đồng)", c => { const dv = A("div").map(v => v == null ? null : Math.abs(v));
    chart(c, L, [
      { type: "bar", label: "Lợi nhuận của cổ đông cty mẹ", data: npp.map(bn), color: "#c7c9cc" },
      { type: "bar", label: "Cổ tức đã trả (dòng tiền)", data: dv.map(bn), color: "#f59e0b" },
      { type: "line", label: "Cổ tức đã trả/LNST (%)", y: "y1", color: "#111827", data: pct(dv, npp) }]); });
}

/* ---------- định giá PE/PB/PS ---------- */
function renderVal(key) {
  const rows = valRows[key], box = $("val-" + key);
  if (valCharts[key]) { valCharts[key].destroy(); delete valCharts[key]; }
  if (!rows || !rows.length) { box.querySelector("h3").innerHTML = `<span class="err">Định giá ${key}: không có dữ liệu (kiểm tra mã trong RATIO_CODES)</span>`; return; }
  const f = $("from").value, t = $("to").value;
  const r = rows.filter(x => x.d >= f && x.d <= t && x.v > 0 && x.v < VAL_CAP);
  if (r.length < 3) { box.querySelector("h3").textContent = `Định giá ${key}: không đủ dữ liệu trong khoảng này`; return; }
  const v = r.map(x => x.v), cur = v[v.length - 1], med = median(v), avg = mean(v), s = sd(v);
  const pm = (cur / med - 1) * 100, pa = (cur / avg - 1) * 100;
  box.querySelector("h3").innerHTML = `Định giá ${key} <span class="${cls(pm)}">${fmt(cur)} lần</span>, ` +
    `<span class="${cls(pm)}">${pm < 0 ? "thấp" : "cao"} hơn trung vị ${fmt(Math.abs(pm), 0)}%</span>, ` +
    `<span class="${cls(pa)}">${pa < 0 ? "thấp" : "cao"} hơn trung bình ${fmt(Math.abs(pa), 0)}%</span>`;
  const d = ds(r), n = d.length, k = x => Array(n).fill(+x.toFixed(2));
  valCharts[key] = chart(box, d.map(x => x.d), [
    { type: "line", label: key, data: d.map(x => x.v), color: "#f97316" },
    { type: "line", label: "Trung vị", data: k(med), color: "#9ca3af", dash: true },
    { type: "line", label: "-2 StDv", data: k(avg - 2 * s), color: "#7c3aed", dash: true },
    { type: "line", label: "-1 StDv", data: k(avg - s), color: "#22c55e", dash: true },
    { type: "line", label: "Trung bình", data: k(avg), color: "#6b7280", dash: true },
    { type: "line", label: "+1 StDv", data: k(avg + s), color: "#ef4444", dash: true },
    { type: "line", label: "+2 StDv", data: k(avg + 2 * s), color: "#0ea5e9", dash: true }]);
  return { cur, pm };
}

/* ---------- chạy ---------- */
async function run() {
  const sym = $("sym").value.trim().toUpperCase(); if (!sym) return;
  charts.forEach(c => c.destroy()); charts = []; valCharts = {}; valRows = {}; logs = []; $("log").textContent = "";
  $("grid").innerHTML = ""; $("val").innerHTML = ""; $("view").innerHTML = ""; $("stats").innerHTML = "";
  $("px").textContent = "..."; $("chg").textContent = ""; $("name").textContent = sym; $("eng").textContent = $("ind").textContent = "";

  try { SRV = await getServerData(sym); }
  catch (e) { log("Không kết nối được máy chủ PHP (" + e.message + "). Hãy chạy: php -S localhost:8000 rồi mở http://localhost:8000"); return; }
  (SRV.errors || []).forEach(e => log(e));
  const prof = await loadHeader(sym), price = await loadPrice();

  // Định giá
  $("val").innerHTML = `<div class="bar">Xem dữ liệu định giá từ ngày <input type="date" id="from" value="2010-01-01"> đến ngày <input type="date" id="to" value="${new Date().toISOString().slice(0, 10)}"></div>`;
  const res = {};
  for (const key of ["PE", "PB", "PS"]) {
    const box = document.createElement("div"); box.className = "card"; box.id = "val-" + key; box.style.marginBottom = "16px";
    box.innerHTML = `<h3>Định giá ${key}</h3><canvas></canvas>`; $("val").appendChild(box);
    try { valRows[key] = await loadRatio(sym, RATIO_CODES[key], "2009-12-31"); } catch (e) { log(key + ": " + e.message); }
  }
  $("from").onchange = $("to").onchange = () => ["PE", "PB", "PS"].forEach(renderVal);
  ["PE", "PB", "PS"].forEach(k => res[k] = renderVal(k));

  $("view").innerHTML = ["PE", "PB", "PS"].map(k => `<div class="row"><span>Định giá ${k}</span>` +
    (res[k] ? `<b class="${cls(res[k].pm)}">${res[k].pm < 0 ? "Thấp" : "Cao"} hơn trung vị ${fmt(Math.abs(res[k].pm), 0)}%</b>` : "<b>-</b>") + "</div>").join("");

  // Thống kê bên phải
  let eps = null, bv = null;
  try { const e = await loadRatio(sym, RATIO_CODES.EPS, iso(2)); eps = e.at(-1)?.v; } catch (e) {}
  try { const b = await loadRatio(sym, RATIO_CODES.BVPS, iso(2)); bv = b.at(-1)?.v; } catch (e) {}
  const shares = prof.issueShare, mcap = price && shares ? price * 1000 * shares / 1e9 : null;
  const last = k => valRows[k] && valRows[k].length ? valRows[k].at(-1).v : null;
  const R = (a, b) => `<div class="row"><span>${a}</span><b>${b}</b></div>`;
  $("stats").innerHTML = R("Ngày niêm yết", prof.listedDate || prof.foundedDate || "-") + R("Vốn hóa (tỷ)", fmt(mcap, 1)) +
    R("Cổ phiếu lưu hành (triệu cp)", shares ? fmt(shares / 1e6, 1) : "-") + R("P/E", fmt(last("PE"))) + R("P/B", fmt(last("PB"))) +
    R("P/S", fmt(last("PS"))) + R("EPS trượt 12T", fmt(eps, 0)) + R("BVPS", fmt(bv, 0));

  // Báo cáo tài chính
  try { const st = await loadStatements(sym); renderStatements(st);
        if (st.missing.length) log("Không dò được chỉ tiêu: " + st.missing.join(", ") + " (xem mục danh sách chỉ tiêu bên dưới)"); }
  catch (e) { const c = addCard("Báo cáo tài chính"); c.innerHTML += `<div class="err">${e.message}</div>`; log("BCTC: " + e.message); }
}
async function diagnose() {
  const sym = $("sym").value.trim().toUpperCase(), box = $("diagout");
  box.style.display = "block"; box.value = "Đang kiểm tra...";
  try { const d = await getServerData(sym);
    box.value = `Trang: ${location.href}\nChart.js: ${typeof Chart}\nLỖI MÁY CHỦ:\n${(d.errors || []).join("\n") || "(không có)"}\n\nDEBUG:\n${JSON.stringify(d.debug, null, 1)}`;
  } catch (e) { box.value = "Không gọi được /api/data: " + e.message + "\nHãy chạy php -S localhost:8000 và mở http://localhost:8000"; }
  box.select();
}
$("diag").onclick = diagnose;
$("go").onclick = run;
$("sym").addEventListener("keydown", e => { if (e.key === "Enter") run(); });
run();
</script>
</body>
</html>
