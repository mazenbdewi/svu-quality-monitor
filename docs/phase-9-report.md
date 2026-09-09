# تقرير المرحلة 9 — Backup, Restore & Safe Update

تم تنفيذ الأدوات في working tree الحالي واختبار الاستعادة على MySQL معزول.
هذا تقرير التنفيذ والتحقق، وليس إعلان نشر المرحلة 9 على الحاويات التشغيلية.
هذا سجل تاريخي؛ نتائج النشر اللاحق والإصلاحات النهائية موثقة في
[تقرير الجاهزية النهائي](final-readiness-report.md)، وإجراءات التحديث المعتمدة في
[safe-update.md](safe-update.md).
لم تُستعد قاعدة التشغيل أو ملفاتها، ولم يُضف إليها مستخدم تجريبي.

1. **Architecture:** `BackupService` يدير الحزم والتحقق والاستعادة والاحتفاظ؛
   `BackupDatabase` يعزل تشغيل أدوات MySQL. قفل filesystem يمنع تزامن العمليات.
2. **المحتوى:** قاعدة MySQL + `storage/app/public` + `storage/app/private`، بما
   فيها uploads أو logo محلي. مستبعد: `.env`، logs، sessions، cache، views المجمعة،
   vendor، node_modules وimages. التخزين الخارجي غير مشمول.
3. **Dump:** أدوات MySQL 8.4 الرسمية المثبتة على digest، باستخدام
   `--single-transaction --quick --skip-lock-tables --no-tablespaces
   --set-gtid-purged=OFF --hex-blob --routines --events --triggers`.
   credentials في options file مؤقت 0600 يُحذف في finally؛ لا password في argv.
4. **الموقع:** named volume باسم `backup_data` عند
   `/var/backups/svu-quality-monitor`، خارج nginx؛ directories 0700 وfiles 0600.
5. **Metadata:** format=1، created_at، application/version، Laravel version،
   MySQL engine/version، قائمة migrations، components، file_count وdump_bytes.
6. **Manifest:** حجم وSHA-256 لكل payload file؛ رفض الملف المفقود/الإضافي أو
   المعدّل، symlinks، مسارات traversal، format غير معروف وdump غير متوقع.
7. **Create:** `php artisan backup:create --actor-email=EXISTING_ADMIN_EMAIL`؛
   ينتج Full bundle ويطبع path/size/timestamp/verified. لا يوجد output حر يسمح
   بوضع dump في public directory.
8. **Verify:** `php artisan backup:verify /path/to/BUNDLE --actor-email=EXISTING_ADMIN_EMAIL`.
   الـbundle مجلد وليس tar؛ التحقق يقرأ الملفات الفعلية ويقارن manifest.
9. **Restore:** CLI فقط، ويتطلب `--force --workers-stopped --actor-email=EXISTING_SUPER_ADMIN_EMAIL`.
   توثيق إيقاف جميع writers جزء إلزامي من runbook قبل الأمر.
10. **Pre-restore:** verify/compatibility وفحص وجهات الملفات أولًا، ثم نسخة staged
    خاصة مع إعادة verification ونسخة pre-restore ناجحة قبل أي import. لا bypass.
11. **Failure:** يبقى maintenance مفعّلًا بعد النجاح والفشل حتى تحقق المشغّل.
    لا transaction مزعومة بين DB/files. تُنظف مساحة staging حتى إذا فشل checkpoint.
12. **Retention:** keep_last=14 configurable؛ لا حذف خارج المسار المخصص، ولا حذف
    للملفات المجهولة أو الحزم غير الصحيحة. checkpoints محمية من الحذف التلقائي.
13. **Schedule:** backup الساعة 02:00 وcleanup الساعة 03:00 بحسب APP_TIMEZONE؛
    آخر قراءة تشغيلية كانت UTC. Failures لها operational logging.
14. **Records:** لا جدول backup_runs جديد؛ JSON records خاصة تحت `.runs` تحفظ
    type/start/finish/status/size/error_safe وتبقى مستقلة عن DB restore.
15. **UI:** قسم Backup في System Operations يعرض آخر نجاح/فشل والعمر والحجم
    والحالة، بتحديث 60 ثانية، مع thresholds افتراضية Warning=30h وDown=54h.
16. **Permissions:** Administrator: view/create؛ Super Admin: restore أيضًا؛
    Operator/Viewer: لا وصول للنسخ. Seeder idempotent: 4 roles / 32 permissions /
    84 links في بيئة الاختبار، دون تعديل RBAC التشغيلي.
17. **Audit:** backup.created / verification_failed / restore_started /
    restore_completed / restore_failed / cleanup مرتبطة بالـactor في العمليات
    اليدوية. Scheduled/pre-update تسجل تشغيليًا دون actor وهمي.
18. **Safe Update:** `python3 scripts/safe-update.py --url BASE_URL`؛ orchestration
    على host باستخدام Docker Compose، بلا اعتماد على Git أو git pull.
19. **Preflight:** Docker/config، smoke، DB/schema/admin، login HTTP، volumes
    المطلوبة ومساحة/كتابة backup وupdate-state، وصور الحاويات الحالية.
    File-based maintenance مطلوب لآلية recovery المستقلة عن إقلاع Laravel.
20. **Pre-update:** Full verified backup إلزامي قبل build/migrations/switch؛
    فشله يوقف التحديث. مساره محفوظ في operational update log.
21. **Build/switch:** بناء مع بقاء الصورة القديمة عاملة؛ حفظ recovery tags وIDs؛
    تحديث app/scheduler/queue ثم web باستخدام `--no-deps` دون إعادة إنشاء db.
22. **Migrations:** عرض status من الصورة الجديدة ثم migrate --force وRBAC seeder؛
    لا migrate:fresh أو db:wipe أو migrate:rollback.
23. **Workers:** queue:restart ثم stop بمهلة 120 ثانية، مقابل timeout الحالي
    60 ثانية. Scheduler heartbeat مسموح أثناء maintenance للتحقق قبل reopening.
24. **Post-update (صُحح في المرحلة 12):** clear caches ثم static smoke، وإيقاف web
    ورفع صيانة Laravel لعودة نبض Queue، ثم full smoke حتى 180 ثانية قبل بدء web
    وlogin HTTP. يشمل smoke DB/schema/migrations/cache/storage/active admin
    وRuntime Heartbeat لكل من Scheduler وQueue؛ بلا network monitoring خارجي.
25. **Rollback:** عند الفشل بعد maintenance، إيقاف web والعمال والعودة إلى صور
    app/web السابقة مع بقاء الموقع offline. إذا تعذر إقلاع Laravel، كتابة
    maintenance marker عبر PHP مستقل. لا DB restore destructive تلقائيًا.
26. **Dry-run:** preflight وعرض الخطوات والصور فقط؛ لا build/backup/migrate/switch.
    smoke يستخدم cache probe مؤقتًا يحذفه بعد التحقق.
27. **Disaster runbook:** [backup-and-restore.md](backup-and-restore.md) يشرح
    الاستعادة إلى host جديد بالصورة المطابقة و`.env`/APP_KEY محفوظين خارجيًا؛
    [safe-update.md](safe-update.md) يوضح image rollback وقيود schema recovery.
28. **MySQL verification:** نجح `bash scripts/verify-backup-isolated.sh` على MySQL
    8.4.8 مع قاعدة tmpfs وملفات مؤقتة وشبكة منفصلة بلا منافذ منشورة. نجح أمر
    backup:restore نفسه: عادت الصفوف والملفات، حُذف الملف الإضافي، بقي Super Admin،
    أُنشئ pre-restore، نجح verify وstatic smoke، وبقي maintenance. حُذفت حاويات
    الاختبار وبيانات fixture المؤقتة عند انتهاء التجربة؛ يمكن إعادة توليدها بالسكربت.
29. **ملفات منشأة:** `app/Services/BackupService.php`, `BackupDatabase.php`؛
    `app/Console/Commands/BackupCreate.php`, `BackupVerify.php`, `BackupRestore.php`,
    `BackupCleanup.php`, `SystemSmokeCheck.php`؛ `config/backup.php`؛
    `lang/ar/backup.php`, `lang/en/backup.php`؛ `scripts/safe-update.py`,
    `scripts/compose.backup-drill.yaml`, `scripts/verify-backup-isolated.php`,
    `scripts/verify-backup-isolated.sh`؛ `tests/Feature/BackupTest.php`,
    `tests/safe_update_test.py`؛ وثيقتا التشغيل وهذا التقرير.
30. **ملفات معدّلة لهذه المرحلة:** `.dockerignore`, `.env.example`, `.gitignore`,
    `Dockerfile`, `compose.yaml`, `routes/console.php`,
    `database/seeders/RolesAndPermissionsSeeder.php`,
    `app/Filament/Pages/SystemOperationsPage.php`,
    `resources/views/filament/pages/system-operations-page.blade.php`,
    `tests/Feature/OperationalReadinessTest.php`, `docs/backup-and-maintenance.md`.
    تعديلات المراحل السابقة في working tree محفوظة ولا تُنسب إلى هذه المرحلة.
31. **اختبارات مضافة:** 14 اختبار Laravel للنسخ/checksums/restore failures/
    checkpoint/permissions/UI/retention/health/seeder، و6 اختبارات Python لمسار
    update/preflight/backup/build/HTTP failure/rollback/dry-run؛ إضافة إلى MySQL drill.
32. **Full regression:** نجح `php artisan test` كاملًا: 257 اختبارًا و1284 assertion،
    دون فشل، خلال 31.21 ثانية. شُغّل ببيئة testing وقاعدة SQLite في الذاكرة؛
    تحقق MySQL الفعلي منفصل وموثق في البند 28.
33. **Pint:** `vendor/bin/pint --test` ناجح.
34. **Whitespace:** `git diff --check` ناجح.
35. **الحدود:** لم يُجرَ تحديث destructive على التشغيل. مسار safe-update اختُبر
    بمحاكاة العمليات؛ يحتاج التشغيل الحالي أول نشر لأدوات المرحلة 9 وbackup volume
    قبل استخدامه. الحزم غير مشفرة وdump يحتوي بيانات التطبيق الحساسة بطبيعته؛
    metadata/logs لا تتضمن credentials. يلزم نسخ off-host وحفظ APP_KEY منفصلًا.
    checksums ليست توقيع أصالة. Live DB/files ليست snapshot ذرية واحدة. Restore
    يتطلب migration set وMySQL major متوافقين؛ لا يحذف جداول يدوية زائدة تلقائيًا.
    لا دعم S3 أو تشفير أو installer/setup wizard ضمن المرحلة الحالية.

لم يُنفذ commit أو push أو reset/checkout/revert، ولم تبدأ المرحلة 10.
