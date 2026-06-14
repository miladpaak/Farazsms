<?php
/**
 * تب «برنامه‌نویسان» — مستنداتِ API عمومیِ فراز اس ام اس.
 *
 * @package farazsms
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}
?>
<style>
	.fz-dev-wrap{direction:rtl;max-width:920px;line-height:2;color:#1e293b;}
	.fz-dev-hero{background:linear-gradient(135deg,#ecfdf5 0%,#eff6ff 100%);border:1px solid #bbf7d0;border-radius:14px;padding:20px 24px;margin-bottom:22px;}
	.fz-dev-hero h2{margin:0 0 8px;font-size:18px;color:#065f46;}
	.fz-dev-hero p{margin:0;font-size:13.5px;color:#334155;}
	.fz-dev-card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px 22px;margin-bottom:18px;border-right:4px solid #2563eb;}
	.fz-dev-card h3{margin:0 0 6px;font-size:15px;color:#0f172a;}
	.fz-dev-card .desc{font-size:13px;color:#475569;margin:0 0 12px;}
	.fz-dev-card pre{background:#0f172a;color:#e2e8f0;border-radius:8px;padding:14px 16px;direction:ltr;text-align:left;overflow-x:auto;font-family:Menlo,Consolas,monospace;font-size:12.5px;line-height:1.8;margin:8px 0;}
	.fz-dev-card pre .c{color:#94a3b8;}
	.fz-dev-meta{font-size:12.5px;color:#475569;margin:6px 0;}
	.fz-dev-meta code{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:4px;padding:1px 6px;direction:ltr;display:inline-block;}
	.fz-dev-note{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;font-size:13px;color:#92400e;margin-top:6px;}
	.fz-dev-tabbtn{background:transparent;border:none;border-bottom:3px solid transparent;padding:10px 18px;font-size:13.5px;font-weight:600;color:#64748b;cursor:pointer;font-family:inherit;margin-bottom:-2px;border-radius:6px 6px 0 0;}
	.fz-dev-tabbtn:hover{color:#4338ca;background:#f8fafc;}
	.fz-dev-tabbtn.is-active{color:#4338ca;border-bottom-color:#6366f1;}
</style>

<div class="fz-dev-wrap">
	<div class="fz-dev-tabs" style="display:flex;gap:6px;border-bottom:2px solid #e5e7eb;margin-bottom:20px;flex-wrap:wrap;">
		<button type="button" class="fz-dev-tabbtn is-active" data-pane="wp">⚡ توابع وردپرس (سریع)</button>
		<button type="button" class="fz-dev-tabbtn" data-pane="sdk">📦 SDK مستقل PHP</button>
	</div>
	<div class="fz-dev-pane" data-pane="wp">

	<div class="fz-dev-hero">
		<h2>🚀 دیگر لازم نیست توابعِ پیامکی را از صفر بنویسی!</h2>
		<p>
			ما همه‌ی سختی‌ها (اتصال به وب‌سرویس، نرمال‌سازیِ شماره، پترن، دفترچه‌تلفن، مدیریتِ خطا) را یک‌بار پیاده کرده‌ایم.
			تو فقط افزونه‌ی فراز اس ام اس را فعال نگه دار و چند تابعِ ساده را صدا بزن. نه کلیدی در افزونه‌ی خودت بگیر،
			نه کدِ ارسال بنویس — همه‌چیز از تنظیماتِ همین افزونه خوانده می‌شود.
		</p>
	</div>

	<p style="font-size:13.5px;color:#334155;">
		برای سازگار کردنِ افزونه یا قالبِ خودت با فراز اس ام اس، کافی است این توابع را صدا بزنی.
		همه‌ی توابع وقتی در دسترس‌اند که افزونه فعال باشد؛ پس بهتر است اول با
	</p>
	<pre style="background:#0f172a;color:#e2e8f0;border-radius:8px;padding:12px 16px;direction:ltr;text-align:left;font-family:Menlo,Consolas,monospace;font-size:12.5px;">function_exists('farazsms_send_sms')</pre>
	<p style="font-size:13.5px;color:#334155;">وجودشان را بررسی کنی.</p>

	<!-- 0) آمادگی -->
	<div class="fz-dev-card" style="border-right-color:#64748b;">
		<h3>✅ بررسی آمادگی</h3>
		<p class="desc">آیا کلید دسترسی و خطِ ارسال تنظیم شده‌اند؟</p>
<pre>farazsms_is_ready(): bool

<span class="c">// مثال</span>
if ( function_exists('farazsms_is_ready') &amp;&amp; farazsms_is_ready() ) {
    <span class="c">// آماده‌ی ارسال است</span>
}</pre>
	</div>

	<!-- 1) ارسال ساده -->
	<div class="fz-dev-card">
		<h3>۱) ارسالِ پیامکِ ساده (غیرپترن)</h3>
		<p class="desc">یک متنِ دلخواه به یک یا چند شماره می‌فرستد.</p>
<pre>farazsms_send_sms( $recipient, $message, $sender = '' )</pre>
		<p class="fz-dev-meta">
			<code>$recipient</code> یک شماره یا آرایه‌ای از شماره‌ها —
			<code>$message</code> متنِ پیام —
			<code>$sender</code> خطِ ارسال (خالی = پیش‌فرض).
		</p>
		<p class="fz-dev-meta">خروجی: <code>array( 'ok' =&gt; bool, 'message' =&gt; string, 'raw' =&gt; mixed )</code></p>
<pre><span class="c">// ارسال به یک شماره</span>
$res = farazsms_send_sms( '09123456789', 'سلام! سفارش شما ثبت شد.' );
if ( $res['ok'] ) {
    <span class="c">// موفق</span>
} else {
    error_log( $res['message'] );
}

<span class="c">// ارسال به چند شماره</span>
farazsms_send_sms( array('09120000000','09130000000'), 'متن گروهی' );</pre>
	</div>

	<!-- 2) ارسال پترن -->
	<div class="fz-dev-card" style="border-right-color:#7c3aed;">
		<h3>۲) ارسالِ پیامکِ پترن (الگو)</h3>
		<p class="desc">با کدِ پترنی که در پنل ساخته‌ای و متغیرهایش پیامک می‌فرستد (مناسبِ OTP، اطلاع‌رسانیِ سفارش و…).</p>
<pre>farazsms_send_pattern( $recipient, $pattern_code, $variables = array(), $sender = '' )</pre>
		<p class="fz-dev-meta">
			<code>$pattern_code</code> کدِ پترن —
			<code>$variables</code> آرایه‌ی متغیرها مثلِ
			<code>array('code' =&gt; '12345')</code>.
		</p>
<pre>$res = farazsms_send_pattern(
    '09123456789',
    'abc123pattern',
    array( 'code' =&gt; '54321', 'name' =&gt; 'علی' )
);

if ( $res['ok'] ) {
    <span class="c">// پیامکِ پترن ارسال شد</span>
}</pre>
	</div>

	<!-- 3) افزودن به دفترچه تلفن -->
	<div class="fz-dev-card" style="border-right-color:#059669;">
		<h3>۳) ذخیره‌ی شماره در دفترچه‌ی تلفن</h3>
		<p class="desc">یک مخاطب را در دفترچه‌ی تلفنِ پنل ذخیره می‌کند.</p>
<pre>farazsms_phonebook_add( $phonebook_id, $name, $mobile, $prefix = 'man' )</pre>
		<p class="fz-dev-meta">
			<code>$phonebook_id</code> شناسه‌ی دفترچه (از تابعِ فهرست) —
			<code>$prefix</code> یکی از
			<code>man</code> /
			<code>woman</code> /
			<code>co</code> /
			<code>org</code>.
		</p>
<pre>$res = farazsms_phonebook_add( 1024, 'علی احمدی', '09123456789', 'man' );
if ( $res['ok'] ) {
    <span class="c">// در دفترچه ذخیره شد</span>
}</pre>
	</div>

	<!-- 4) فهرست دفترچه‌ها -->
	<div class="fz-dev-card" style="border-right-color:#0891b2;">
		<h3>۴) فهرستِ دفترچه‌های تلفن</h3>
		<p class="desc">لیستِ دفترچه‌های موجود را برمی‌گرداند (برای انتخابِ شناسه).</p>
<pre>farazsms_phonebook_list(): array</pre>
		<p class="fz-dev-meta">خروجی: آرایه‌ای از <code>array( 'id' =&gt; ..., 'title' =&gt; ... )</code></p>
<pre>$books = farazsms_phonebook_list();
foreach ( $books as $book ) {
    echo $book['id'] . ' - ' . $book['title'];
}</pre>
	</div>

	<!-- 5) موجودی -->
	<div class="fz-dev-card" style="border-right-color:#d97706;">
		<h3>۵) دریافتِ موجودیِ پنل</h3>
		<p class="desc">موجودیِ حساب را (به تومان، فرمت‌شده) برمی‌گرداند یا <code>false</code> در صورتِ خطا/مسدودی.</p>
<pre>farazsms_get_credit(): string|false

<span class="c">// مثال</span>
$credit = farazsms_get_credit();</pre>
	</div>

	<div class="fz-dev-note">
		💡 <strong>برای توسعه‌دهندگانِ سایر افزونه‌ها:</strong>
		برای سازگار کردنِ افزونه‌ات با فراز اس ام اس نیازی به کارِ خاصی نیست — همین مستندات را بخوان و فقط توابعِ بالا را
		صدا بزن تا از همه‌ی قابلیت‌های ارسالِ پیامک، پترن و دفترچه‌تلفنِ ما استفاده کنی. کلید و خطِ ارسال از تنظیماتِ همین
		افزونه خوانده می‌شود؛ کاربرِ تو فقط یک‌بار آن‌ها را وارد می‌کند.
	</div>

	</div><!-- /pane wp -->

	<div class="fz-dev-pane" data-pane="sdk" style="display:none;">
		<div class="fz-dev-hero" style="background:linear-gradient(135deg,#eef2ff 0%,#f5f3ff 100%);border-color:#c7d2fe;">
			<h2 style="color:#3730a3;">📦 SDK مستقل PHP (بدونِ وردپرس)</h2>
			<p>یک کلاسِ PHP خالص که همه‌ی سرویس‌های وب‌سرویسِ فراز را پوشش می‌دهد و در هر پروژه‌ی PHP (Laravel، Symfony، اسکریپتِ ساده یا افزونه‌ی وردپرس) کار می‌کند. این مسیرِ پیشنهادی برای توسعه‌دهندگانِ خارج از وردپرس است.</p>
		</div>

		<div class="fz-dev-card" style="border-right-color:#6366f1;">
			<h3>دریافت و نصب</h3>
			<p class="desc">پوشه‌ی <code>developer-sdk</code> به‌همراهِ افزونه ارائه می‌شود (و افزونه‌ی نمونه‌ی وردپرس در <code>sample-plugin/faraz-sms-sdk-demo</code>). با Composer یا بدونِ آن قابلِ استفاده است.</p>
<pre><span class="c">// با Composer</span>
composer require farazsms/php-sdk

<span class="c">// بدونِ Composer</span>
require '/path/to/developer-sdk/autoload.php';</pre>
			<p class="fz-dev-meta">نیازمندی: <code>PHP >= 7.4</code> + <code>ext-curl</code> + <code>ext-json</code></p>
		</div>

		<div class="fz-dev-card" style="border-right-color:#6366f1;">
			<h3>شروعِ سریع</h3>
<pre>use FarazSms\Client;

$client = new Client('YOUR_API_KEY', [
    'line_number' =&gt; '3000xxxx',
]);

$res = $client-&gt;sendSimple('09120000000', 'سلام دنیا');
if ($res-&gt;isSuccess()) { <span class="c">/* ارسال شد */</span> }</pre>
		</div>

		<div class="fz-dev-card" style="border-right-color:#6366f1;">
			<h3>پترن (ساخت و ارسال)</h3>
<pre><span class="c">// یک‌بار: ساختِ پترن (سپس در پنلِ فراز تأیید شود)</span>
$code = $client-&gt;createPattern('کد تایید شما %code% است', ['code'], 1, 'OTP')
              -&gt;getData()['code'];

<span class="c">// ارسال با پترن</span>
$client-&gt;sendPattern('09120000000', $code, ['code' =&gt; 12345]);</pre>
		</div>

		<div class="fz-dev-card" style="border-right-color:#6366f1;">
			<h3>سرویس‌های پوشش‌داده‌شده</h3>
			<p class="desc">همه از روی کدِ تولیدِ واقعی استخراج و تست شده‌اند.</p>
			<p class="fz-dev-meta"><code>sendSimple</code> · <code>sendPattern</code> · <code>createPattern</code> · <code>getPattern</code> · <code>updatePattern</code> · <code>getBalance</code> · <code>getProfile</code> · <code>chargeWallet</code> · <code>getAccessibleLines</code> · <code>listPhoneBooks</code> · <code>createPhoneBook</code> · <code>getPhoneBookContacts</code> · <code>addPhoneBookContact</code> · <code>listSendRequests</code> · <code>getSendRequest</code> · <code>getSendRequestItems</code> · <code>submitTicket</code></p>
		</div>

		<div class="fz-dev-card" style="border-right-color:#6366f1;">
			<h3>مدیریتِ خطا</h3>
<pre>use FarazSms\Exception\{AuthenticationException, ValidationException, FarazSmsException};

try {
    $client-&gt;sendSimple('09120000000', 'سلام');
} catch (AuthenticationException $e) {   <span class="c">// کلید نامعتبر</span>
} catch (ValidationException $e) {        <span class="c">// $e-&gt;getErrors()</span>
} catch (FarazSmsException $e) {          <span class="c">// هر خطای دیگر</span>
}</pre>
		</div>

		<div class="fz-dev-card" style="border-right-color:#6366f1;">
			<h3>تست</h3>
			<p class="desc">بدونِ شبکه و بدونِ وابستگی، با یک دستور:</p>
<pre>php developer-sdk/tests/run-tests.php   <span class="c"># 28 تست سبز</span></pre>
		</div>

		<div class="fz-dev-note" style="background:#eef2ff;border-color:#c7d2fe;color:#3730a3;">
			🧩 <strong>افزونه‌ی نمونه:</strong> در <code>sample-plugin/faraz-sms-sdk-demo</code> یک افزونه‌ی کاملِ وردپرس هست که همین SDK را در <code>lib/</code> بسته‌بندی کرده و نشان می‌دهد چطور آن را به منو، تنظیمات و قلابِ ثبت‌نام وصل کنید.
		</div>
	</div><!-- /pane sdk -->

	<script>
	(function(){
		var btns = document.querySelectorAll('.fz-dev-tabbtn');
		var panes = document.querySelectorAll('.fz-dev-pane');
		btns.forEach(function(b){
			b.addEventListener('click', function(){
				btns.forEach(function(x){ x.classList.remove('is-active'); });
				b.classList.add('is-active');
				var key = b.getAttribute('data-pane');
				panes.forEach(function(p){ p.style.display = (p.getAttribute('data-pane')===key) ? '' : 'none'; });
			});
		});
	})();
	</script>
</div>
