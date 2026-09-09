# Public deployment closure — 2026-09-08

**INTERNAL / ACADEMIC: READY. PUBLIC INTERNET: NEEDS ATTENTION.**
لم يُغيَّر التشغيل أو المعمارية، ولم يُضف سلوك تطبيقي أو تُصدر شهادة.
إعدادات HTTPS أدناه opt-in فقط؛ ليست مفعلة على عنوان HTTP الحالي.

## HTTPS: configuration prepared, activation pending

الملف `docker/nginx/https.conf.template` يستخدم نفس nginx الحالي لإنهاء TLS،
وشهادة حقيقية تحت `/etc/letsencrypt/live/<domain>/`، وTLS 1.2/1.3.
HTTP يحوَّل بـ308 إلى hostname ثابت، مع استثناء ACME challenge فقط.
`compose.https.example.yaml` يستبدل port bindings بـ80/443 ولا يمس DB أو volumes.
يشترط Docker Compose الذي يدعم `!override` (2.24.4+)، وهو مدعوم محليًا.

الخطوات المتبقية للمشغّل:

1. توفير hostname حقيقي، وضبط A/AAAA على المضيف الصحيح وفتح 80/443.
   لا تضع URL أو path أو رموز nginx داخل PUBLIC_DOMAIN؛ استخدم DNS hostname فقط.
2. توفير شهادة موثوقة. يمكن استخدام Certbot المثبت على المضيف: لأول إصدار،
   `certbot certonly --standalone -d "$PUBLIC_DOMAIN"` بعد التأكد من أن port 80
   متاح وأن DNS صحيح. لا توقف خدمة أخرى تشغل المنفذ دون تنسيق. إذا تعذر HTTP-01،
   يُستخدم DNS-01 وفق مزود DNS الحقيقي؛ لم يُفترض أو يُنشأ أي credential.
3. أنشئ `/var/lib/svu-acme` على المضيف، واضبط صلاحيات certificate private key
   للمشغّل/root فقط. يُركب كامل `/etc/letsencrypt` read-only للحفاظ على symlinks.
4. عيّن `PUBLIC_DOMAIN` خارج المستودع، وراجع التركيب دون طباعة config المحتوي
   على environment: `docker compose -f compose.yaml -f compose.https.example.yaml config --quiet`.
5. بعد وجود الشهادة الفعلية، اختبر nginx في one-off container مع overlay، ثم
   نفّذ تبديل الخدمات المعنية مع `--no-deps` وفق runbook الصيانة؛ لا تعِد إنشاء DB.
   لا تفعّل HTTPS overlay قبل الشهادة: nginx سيرفض الإقلاع دونها.
6. اختبر HTTP→HTTPS، سلسلة الشهادة والـhostname، Login وLivewire، وغياب mixed
   content، وأن كوكي الجلسة تحمل Secure وHttpOnly. يُحدَّث APP_URL الخارجي أيضًا
   إلى عنوان https الصحيح في إعداد المشغّل، دون تغيير APP_KEY.
7. جدّد عبر `certbot renew --webroot -w /var/lib/svu-acme` بعد ضبط renewal configuration
   لهذا المسار، ثم اختبر `certbot renew --dry-run`. أضف deploy hook يعيد تحميل nginx
   بعد نجاح التجديد، مثل `docker compose exec -T web nginx -s reload` من مسار المشروع.

الـoverlay يضبط APP_URL=https وSESSION_SECURE_COOKIE=true للتطبيق والعمال؛
ويحافظ على HttpOnly/SameSite الحالية. `fastcgi_param HTTPS on` يجعل Laravel يرى
HTTPS الحقيقي عند إنهائه داخل nginx، **دون الثقة في X-Forwarded-Proto من العميل**.
لا حاجة لإضافة trusted proxies في هذا المسار المباشر.

إذا اختارت الاستضافة reverse proxy خارجيًا ينهي TLS، فلا تستخدم الإعداد المباشر
دون مراجعة: تحتاج عنوان proxy الفعلي، تقييد الوصول إلى origin، وتنظيف forwarded
headers عند proxy. اضبط `trustProxies(at: [...])` في Laravel على IP/CIDR محدد
والـheaders المستخدمة فقط (`X-Forwarded-For/Host/Port/Proto` بحسب إعداد المزود).
لا `*` أو ثقة بجميع العملاء، ولا forceScheme لتعويض ثقة غير صحيحة. هذه الحالة
معلقة على تفاصيل الاستضافة، وليست مفعلة أو مجرَّبة هنا.

التحقق المحلي: تركيب Compose overlay نجح باسم `.invalid` محجوز لفحص الصياغة فقط؛
`nginx -t` للـHTTP التشغيلي نجح. **لم يُختبر TLS handshake أو nginx TLS config
بشهادة فعلية، ولم يُدّع نجاح HTTPS.**

## Off-host encrypted backup: design/config ready, delivery pending

الاختيار التشغيلي المقترح: Restic إلى مستودع SFTP يملكه المشغّل، دون خدمة مدفوعة
مفروضة. Restic يوفر تشفير repository؛ لا تشفير منزلي ولا نسخة plaintext في الوجهة.
الأداة غير مثبتة محليًا حاليًا، ولا توجد وجهة خارجية معتمدة؛ لم يحدث رفع خارجي.

انسخ `docker/offhost.env.example` إلى `/etc/svu-quality-monitor/offhost.env`
بصلاحية 0600، واضبط RESTIC_REPOSITORY وRESTIC_PASSWORD_FILE على قيم حقيقية.
اجعل password file وSSH identity خارج المشروع، واحتفظ بنسخة استرداد منفصلة من
كلمة مرور المستودع. استخدم known_hosts موثوقًا وStrictHostKeyChecking؛ لا كلمات
مرور ضمن URL أو command arguments. خصص حساب backup محدودًا ومسارًا خاصًا.

بعد تثبيت Restic وتهيئة وجهة يملكها المشغّل، تُنفّذ هذه الأوامر من مسار المشروع
بعد اختيار اسم حزمة معروف بلا `/` أو `..` في BUNDLE:

```bash
set -euo pipefail
set -a
. /etc/svu-quality-monitor/offhost.env
set +a
test -n "$RESTIC_REPOSITORY" && test -r "$RESTIC_PASSWORD_FILE"
# restic init  # مرة واحدة فقط لمستودع جديد مقصود
docker compose exec -T app php artisan backup:verify "/var/backups/svu-quality-monitor/$BUNDLE"
restic backup --stdin-from-command --stdin-filename "$BUNDLE.tar" -- \
  docker compose exec -T app tar -C /var/backups/svu-quality-monitor -cf - "$BUNDLE"
restic snapshots
restic check --read-data
```

`--stdin-from-command` يمنع نجاحًا مضللًا إذا فشل tar؛ لا تُشغّل restic عبر pipeline
يتجاهل exit code للمنتج. هذه أوامر ربط وليست جدولة مثبتة. لا forget/prune تلقائيًا؛
يختار المشغّل retention وحماية checkpoints/append-only حسب الوجهة ثم يختبر استرجاعًا
معزولًا من المستودع الخارجي. احتفظ بـ`.env`/APP_KEY منفصلين وبأمان؛ لا يدخلان الحزم.
لا يكفي نجاح upload: يلزم تنزيل snapshot والتحقق من الحزمة وتجربة استعادتها.

## Restore drill: PASS

المصدر: أحدث حزمة ناجحة عند المراجعة:
`backup-20260908-114426-b2ef186b-511d-4e0a-8835-fcff541da184`، 92,278 bytes.
نجح backup:verify التشغيلي أولًا، ثم نُسخت إلى مجلد مؤقت خاص ومُررت read-only.

استخدم `scripts/compose.closure-drill.yaml` مشروعًا منفصلًا باسم
`svu-closure-drill-0bqxgj` دون production env_file أو ports أو volumes تشغيلية.
استُخدم MySQL 8.4.8 وtmpfs وقاعدة closure_drill جديدة. نُفذ **backup:restore الفعلي**
بشروطه (checkpoint وصيانة وصلاحيات)، ثم استيراد raw مستقل إلى closure_expected
لمقارنة الجداول والصفوف. طابقت كل المقارنات، بما فيها المستخدم والأدوار والترحيلات
والبيانات المشفرة كما هي والملفات. احتُفظ بالمسؤول الأعلى وبالصيانة بعد restore.
إضافة حدث restore إلى audit في البيئة المؤقتة مستثناة من مقارنة السجلات الأصلية.
لم يُستعمل APP_KEY التشغيلي؛ مقارنة ciphertext ليست اختبارًا لفك تشفيره في disaster recovery.
أُزيلت حاويتا الاختبار وشبكته بعد النجاح؛ قواعد tmpfs لم تبقَ على المضيف.
المصدر التشغيلي الأصلي محفوظ ولم يتغير؛ حُذفت نسخة الاختبار المحلية وملفات المتصفح
المؤقتة بعد تدوين النتيجة. يمكن إعادة التجربة بالملفات scripts/closure-* أعلاه.

## Real monitoring acceptance: PASS in isolation

استُخدم endpoint HTTP حقيقي على loopback داخل verifier فقط. نفّذ
CheckMonitoredServiceJob → ServiceCheckRunner طلبات حقيقية:

`200 → 503 → 503 → 200 → 200`

النتيجة: `healthy → pending_failure → down → recovering → healthy`؛ حادث واحد
بـconfirmed_at ثم resolved_at وحالة closed. القناة معطلة للخدمة التجريبية؛
لا طلبات إلى طرف خارجي ولا خدمة أو حادث اختبار على production.
هذا قبول HTTP/incident engine عبر sync job معزول، وليس اختبار daemon workers
كاملًا؛ نبض Scheduler/Queue التشغيلي اختُبر منفصلًا ونجح.

## Notifications: external acceptance PENDING

قناتا Telegram وEmail معطلتان في التشغيل. configuration/readiness موجودة ومختبرة
داخليًا. لم تُقرأ tokens أو كلمات SMTP من ملفات أسرار، ولم تُنشأ credentials أو
تُرسل رسائل. يتطلب الاختبار الخارجي أن يربط المشغّل القناة الحقيقية عبر واجهة
الإعدادات الآمنة ثم يرسل Test Notification إلى مستلم معتمد.

## Browser smoke: public/login PASS; authenticated UI PENDING

استُخدم Chrome headless حقيقي على runtime الحالي. نجحت صفحة Login باللغتين،
مع مراجعة صورها بصريًا، RTL/LTR صحيح، ودون أخطاء JavaScript أو مفاتيح ظاهرة.
نجحت 22 زيارة إجمالًا تشمل تبديل اللغة؛ كل طلب مجهول للصفحات المحمية انتهى
بـ302 إلى Login ثم 200. لم تُطلب أو تُجرّب كلمات مرور.

القائمة: Dashboard، Services، Incidents، Maintenance، Reports، Users، Audit Log،
System Operations وDiagnostics. SLA يعرض عبر dashboard/reports، لا route مستقلة.
هذا يثبت سلوك الحماية، **لا عرض محتوى الصفحات بعد تسجيل الدخول في المتصفح**.
مراجعة HTTP Kernel السابقة للصفحات باللغتين موثقة في final-readiness-report.md؛
لا تُقدَّم بدلًا من browser acceptance تفاعلي كامل.
رُفض اقتراح إنشاء جلسة مسؤول مباشرة لأنه يتجاوز المصادقة؛ لم يُنفذ ولم يُتحايل عليه.
يُكمل المستخدم القائمة بجلسة دخول عادية معتمدة، دون إرسال password أو APP_KEY هنا.

## Final status and operator actions

- INTERNAL / ACADEMIC: **READY**؛ readiness الداخلي وDB/Scheduler/Queue سليمة.
- PUBLIC INTERNET: **NEEDS ATTENTION**؛ التطبيق ليس فاشلًا بسبب نواقص الاستضافة.
- المتبقي: domain/DNS، شهادة وتجديدها و80/443، سياسة proxy/firewall، وجهة نسخ
  خارجية ومواد استرداد آمنة، ربط قناة حقيقية عند الحاجة، وفحص المتصفح المصادق.
- لم يتغير compose.yaml أو nginx التشغيلي، ولم يُنشأ commit/push أو Feature.

### مراجع الإعداد

- Nginx: https://nginx.org/en/docs/http/configuring_https_servers.html
- Laravel: https://laravel.com/docs/12.x/requests#configuring-trusted-proxies
- Let's Encrypt: https://letsencrypt.org/docs/challenge-types/
- Restic: https://restic.readthedocs.io/en/stable/030_preparing_a_new_repo.html
- Restic backup: https://restic.readthedocs.io/en/stable/040_backup.html
