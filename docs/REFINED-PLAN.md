# แผนพัฒนา (ฉบับปรับปรุง) — ปลั๊กอินล้างข้อมูลผู้เรียน Tutor LMS เมื่อลบบัญชี WordPress

> เอกสารนี้เป็นแผนต้นฉบับของคุณที่ผ่านการตรวจสอบกับ **โครงสร้างข้อมูลจริงของ Tutor LMS** (จากซอร์สโค้ดปัจจุบันของปลั๊กอิน `themeum/tutor`) แล้ว
> ส่วนที่มีเครื่องหมาย ✅ คือยืนยันถูกต้อง, ⚠️ คือจุดที่ต้องแก้ไข/ระวัง, ➕ คือรายละเอียดที่เพิ่มเข้ามาให้ครบถ้วน

Plugin slug: `tutor-user-cleanup`

---

## สรุปการเปลี่ยนแปลงสำคัญจากแผนเดิม

| หัวข้อ | แผนเดิม | ข้อเท็จจริงจากซอร์ส Tutor LMS | สิ่งที่ปรับ |
|---|---|---|---|
| Hook เพิ่ม Checkbox | "ใช้ WordPress Hook ในหน้าลบบัญชี" (ไม่ระบุ) | `delete_user_form( $current_user, $userids )` | ระบุ hook ให้ชัด ✅ |
| จังหวะล้างข้อมูล | หลังผู้ดูแลกดยืนยัน | `delete_user( $id, $reassign, $user )` ยิงต่อผู้ใช้ **ก่อน** ลบจริง | ใช้ hook นี้เป็นจุดล้าง ✅ |
| Enrollment | "Custom Post Type / `wp_delete_post()`" | CPT `tutor_enrolled` (`post_author`=ผู้เรียน, `post_parent`=คอร์ส) | ยืนยัน + ระบุ meta ที่เกี่ยว ✅ |
| สถานะเรียนจบคอร์ส | "Custom Post Type" | เก็บใน **`wp_comments`** (`comment_type='course_completed'`) ไม่ใช่ CPT | ⚠️ แก้จุดนี้ |
| สถานะเรียนจบบทเรียน | "Custom Post Type" | เก็บใน **usermeta** `_tutor_completed_lesson_id_{lesson_id}` | ⚠️ แก้จุดนี้ |
| Quiz Attempts / Answers | "ลบ Answer แล้วลบ Attempt" | ตาราง `tutor_quiz_attempts` + `tutor_quiz_attempt_answers` (FK `quiz_attempt_id`) | ยืนยัน + ใช้ API สำเร็จรูป ✅ |
| ป้องกันการลบข้อมูลการเงิน | ระบุกว้าง ๆ | ตาราง `tutor_earnings`, `tutor_withdraws`, WC/Tutor Orders | ระบุตาราง/เงื่อนไขชัด ✅ |

---

## 1. โครงสร้างข้อมูล Tutor LMS ที่ตรวจสอบแล้ว (Reference)

### 1.1 ข้อมูลที่ต้อง "ล้าง" (Learner Data)

| ข้อมูล | ที่จัดเก็บจริง | วิธีลบที่แนะนำ |
|---|---|---|
| การลงทะเบียนเรียน (Enrollment) | CPT `tutor_enrolled`: `post_author`=user, `post_parent`=course, `post_status` ปกติ `completed` (อาจมี `cancel`,`pending`) | `wp_delete_post( $enroll_id, true )` — ลบ post + postmeta ในตัว |
| Meta ผูก Enrollment | postmeta บนโพสต์ `tutor_enrolled`: `_tutor_enrolled_by_order_id`, `_tutor_enrolled_by_product_id` | ถูกลบพร้อม `wp_delete_post()` (ไม่แตะ Order เอง) |
| สถานะเรียนจบคอร์ส | `wp_comments`: `comment_type='course_completed'`, `comment_agent='TutorLMSPlugin'`, `user_id`, `comment_post_ID`=course | `delete_course_progress()` หรือ `wpdb->delete($wpdb->comments, ...)` |
| สถานะเรียนจบบทเรียน | usermeta: `_tutor_completed_lesson_id_{lesson_id}`, `_lesson_reading_info` | `delete_user_meta()` ตาม pattern |
| ประวัติทำแบบทดสอบ | ตาราง `{prefix}tutor_quiz_attempts` (`attempt_id`,`user_id`,`quiz_id`,`course_id`,…) | `QuizModel::delete_quiz_attempt($ids)` |
| คำตอบแบบทดสอบ | ตาราง `{prefix}tutor_quiz_attempt_answers` (FK `quiz_attempt_id` → `attempt_id`) | ลบอัตโนมัติภายใน `delete_quiz_attempt()` |
| ความคืบหน้า/Assignment (Pro) | ทำงานผ่าน hook `do_action('delete_tutor_course_progress', $course_id, $user_id)` | เรียก `delete_course_progress()` ให้ addon ล้างเอง |
| Gradebook ผล (Pro) | ตาราง `{prefix}tutor_gradebooks_results` (ถ้ามี) | ลบ row ตาม `user_id` **ถ้าตารางมีอยู่** |

### 1.2 ข้อมูลที่ต้อง "เก็บไว้" (ห้ามลบอัตโนมัติ)

| ข้อมูล | ที่จัดเก็บจริง | เหตุผล |
|---|---|---|
| WooCommerce Orders | โพสต์ `shop_order` / HPOS `wc_orders` | การเงิน/ตรวจสอบย้อนหลัง |
| Tutor Native Orders (Tutor 3.x) | ตาราง `tutor_orders`, `tutor_ordermeta` | การเงิน |
| รายได้ Instructor | ตาราง `tutor_earnings` | การเงิน |
| การถอนเงิน | ตาราง `tutor_withdraws` | การเงิน |
| รีวิว/เรตติ้งคอร์ส | `wp_comments` `comment_type='tutor_course_rating'` (+ commentmeta `tutor_rating`) | ตามข้อกำหนด |
| คำถาม–คำตอบ (Q&A) | `wp_comments` `comment_type='tutor_q_and_a'` | ตามข้อกำหนด |
| คอร์สที่สร้างในฐานะ Instructor | CPT `courses` (`post_author`=user) | ไม่ใช่ข้อมูลผู้เรียน |
| นิยามคำถาม Quiz | ตาราง `tutor_quiz_questions`, `tutor_quiz_question_answers` | เนื้อหาคอร์ส ไม่ใช่ข้อมูลผู้เรียน |

> ⚠️ **ข้อควรระวังสำคัญ:** ฟังก์ชัน `cancel_course_enrol('delete')` ของ Tutor เองจะ **ลบ meta ของ WooCommerce order** ทิ้งด้วย ปลั๊กอินของเราจะ **ไม่** ทำแบบนั้น — เราลบเฉพาะโพสต์ `tutor_enrolled` และ postmeta ของมันเท่านั้น เพื่อไม่แตะข้อมูลการเงิน

---

## 2. WordPress Hooks และการตรวจสอบสิทธิ์ที่ยืนยันแล้ว

| จุด | Single Site | Multisite (Network) |
|---|---|---|
| แสดง Checkbox | action `delete_user_form( $current_user, $userids )` | ไม่มี hook ในคอร์ → inject ด้วย JS ที่ `network/users.php` (best-effort) |
| จังหวะล้างข้อมูล | action `delete_user( $id, $reassign, $user )` | action `wpmu_delete_user( $id )` แล้ววนทุก site จาก `get_blogs_of_user()` |
| Nonce | `check_admin_referer('delete-users')` | `check_admin_referer('ms-users-delete')` |
| Capability | `current_user_can('delete_users')` | `manage_network_users` |

**เงื่อนไขก่อนล้าง (Guard) ที่ต้องผ่านครบทุกข้อ:**
1. มี capability ลบผู้ใช้
2. Nonce ถูกต้อง (`delete-users` / `ms-users-delete`)
3. มีการติ๊ก checkbox ของเรา (`$_REQUEST['tutor_user_cleanup']`)
4. Tutor LMS เปิดใช้งาน (`function_exists('tutor_utils')` / class `TUTOR\Tutor`)
5. `user_id` มีอยู่จริง (`get_userdata()` คืนค่า)

> หมายเหตุ: `delete_user` ยังถูกยิงเมื่อมีการลบผู้ใช้จากที่อื่น (REST/WP-CLI/WooCommerce) ด้วย — ดังนั้น **ต้องล้างเฉพาะเมื่อ checkbox ถูกติ๊กจากหน้า users.php เท่านั้น** มิฉะนั้นไม่ทำอะไร

---

## 3. ลำดับการล้างข้อมูลต่อผู้ใช้ 1 คน (Cleanup Pipeline)

```
เริ่ม (ต่อ user_id)
 ├─ 0. ตรวจ Guard ครบ 5 ข้อ → ถ้าไม่ผ่าน: บันทึก log + ข้าม
 ├─ 1. ตรวจเวอร์ชัน Tutor + ว่าตาราง/คอลัมน์ที่จำเป็นมีจริง
 ├─ 2. query tutor_enrolled ทุก post_status → รายการคอร์สทั้งหมด
 ├─ 3. ต่อคอร์ส: delete_course_progress(course_id, user_id)
 │        (ล้าง quiz attempts+answers ของคอร์ส, course_completed, lesson meta, ยิง hook addon)
 ├─ 4. Sweep กันตกหล่น: ลบ quiz attempts ทั้งหมดของ user (attempt_id WHERE user_id=?) ผ่าน delete_quiz_attempt()
 ├─ 5. Sweep lesson meta: ลบ usermeta ทุก key ที่ขึ้นต้น _tutor_completed_lesson_id_  + _lesson_reading_info
 ├─ 6. Sweep course_completed: ลบ comments ของ user ที่ type=course_completed
 ├─ 7. ลบโพสต์ enrollment ทั้งหมด: tutor_enrolled WHERE post_author=user (ทุกสถานะ) → wp_delete_post(force)
 ├─ 8. Gradebook (ถ้ามีตาราง): ลบ tutor_gradebooks_results WHERE user_id=?
 ├─ 9. ล้าง cache/transient ที่เกี่ยวกับผู้เรียน
 ├─ 10. do_action('tutor_user_cleanup_after_user', $user_id, $summary)  ← ให้ปลั๊กอินอื่นล้างต่อ
 └─ 11. บันทึก log (requested / deleted / failed ต่อประเภท + ผลลัพธ์)
```

- **Dry-Run:** ใช้ใน preview/reset flow เท่านั้น; delete-user flow ที่ติ๊ก opt-in จะเป็น live เสมอเพื่อไม่ทิ้ง orphan rows หลังบัญชีหาย
- **การแยกความผิดพลาด:** แยกต่อ operation และต่อ item — จุดหนึ่งพังไม่หยุด operation อิสระที่เหลือ และรายงาน `partial`
- **ป้องกันประมวลผลซ้ำ:** เก็บ set ของ `(blog_id, user_id)` ใน request เดียว เพื่อให้ network cleanup ยังทำครบทุก site

> ✅ ใช้ API สำเร็จรูป `delete_course_progress()` และ `QuizModel::delete_quiz_attempt()` แทนการเขียน SQL เอง เพื่อให้ทนต่อการเปลี่ยนสคีมาระหว่างเวอร์ชัน และให้ addon ล้างข้อมูลของตัวเองผ่าน hook

---

## 4. โครงสร้างปลั๊กอิน (ปรับปรุง)

```
tutor-user-cleanup/               ← repo root = ตัวปลั๊กอิน
├── tutor-user-cleanup.php        Bootstrap + header + guard direct access
├── uninstall.php                 ลบ option + ตาราง log ตอนถอนปลั๊กอิน
├── readme.txt                    มาตรฐาน WP.org
├── includes/
│   ├── class-plugin.php          Singleton, wire hooks ทั้งหมด
│   ├── class-settings.php        ตัวเลือกประเภทข้อมูล + retention/version override
│   ├── class-admin-ui.php        Checkbox บนฟอร์มลบ + enqueue + settings page
│   ├── class-tutor-data-repository.php  ทุก query ของ Tutor + ตรวจ version/ตาราง/คอลัมน์
│   ├── class-cleanup-service.php  ควบคุม pipeline, guard, dry-run, error isolation
│   ├── class-reset-learner.php   Row/Bulk action "รีเซ็ตผู้เรียน" โดยไม่ลบบัญชี
│   └── class-logger.php          ตาราง log + อ่าน log
├── assets/
│   ├── admin.js                  ยืนยันเพิ่มเติม + inject checkbox บน network
│   └── admin.css
└── tests/                        Unit tests แบบรันได้ด้วย PHP ล้วน (stub WP)
```

---

## 5. แผนพัฒนาเป็นระยะ (คงเดิม + จับคู่กับไฟล์)

- **ระยะ 1 วิเคราะห์:** ✅ เสร็จแล้ว (เอกสารนี้)
- **ระยะ 2 MVP:** `class-admin-ui` (checkbox+nonce), `class-cleanup-service` (enrollment, completion, quiz) — single user
- **ระยะ 3 ความปลอดภัย:** `class-logger`, Dry-Run, ตรวจ version, กันซ้ำ, error handling
- **ระยะ 4 ขยาย:** Bulk delete, Multisite, Pro add-ons (ผ่าน hook), settings page, เลือกประเภทข้อมูล, `class-reset-learner`
- **ระยะ 5 ทดสอบ/ขึ้นระบบ:** unit + integration + regression, backup, staging

---

## 6. เกณฑ์การยอมรับงาน (คงเดิม — ตรวจสอบได้)

- ผู้ดูแลเห็น checkbox ตอนลบบัญชี ✅
- ไม่ติ๊ก = ข้อมูล Tutor ไม่ถูกแตะ ✅
- ติ๊ก = Enrollment/Progress/Quiz Attempts/Answers ถูกล้างครบ ✅
- ไม่กระทบคำสั่งซื้อ/การเงิน (ตาราง earnings, withdraws, orders, WC) ✅
- สมัครใหม่ด้วยอีเมลเดิมแล้วเริ่มเรียนใหม่ได้ ✅
- รองรับลบหลายบัญชี + มี log + ไม่มี PHP/DB error ✅

---

## 7. ความเสี่ยง/ข้อจำกัดที่ต้องรู้

1. **Multisite network screen ไม่มี hook สำหรับ checkbox** — เราแก้ด้วย JS inject (best-effort) ถ้า WP เปลี่ยน markup อาจต้องปรับ ทางเลี่ยงคือใช้เมนู "รีเซ็ตผู้เรียน"
2. **Tutor Pro add-ons** — เราพึ่ง hook `delete_tutor_course_progress` เป็นหลัก ข้อมูล add-on ที่ไม่ผูกกับ hook นี้อาจตกหล่น → เปิด `tutor_user_cleanup_after_user` ให้ hook เพิ่มได้
3. **Object cache ภายนอก (Redis/Memcached)** — เราล้าง cache ที่รู้จัก + `wp_cache_delete` เฉพาะจุด ไม่ flush ทั้งระบบ
4. **ต้อง backup ก่อนใช้จริง** และแนะนำให้ลองโหมด Dry-Run ก่อนเสมอ

---

## 8. การ Pin เวอร์ชัน Tutor LMS (ป้องกันสคีมาเปลี่ยน)

Tutor LMS มีการเปลี่ยนรูปแบบการเก็บข้อมูลเป็นระยะ (เช่น **เวอร์ชัน 4.0** ก.ค. 2026 ที่ยกเครื่องใหม่ทั้งหมด) หากปลั๊กอินรันคำสั่งลบกับสคีมาที่ไม่รู้จัก อาจลบผิดตาราง/ผิดแถวได้ จึงกำหนด **ช่วงเวอร์ชันที่รองรับ** ไว้:

```php
define( 'TUTOR_USER_CLEANUP_MIN_TUTOR_VERSION',    '2.0.0' );
define( 'TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION', '4.0.1' ); // เวอร์ชันเสถียรล่าสุดที่ตรวจสอบ data model แล้ว
```

**พฤติกรรมเมื่อเวอร์ชันอยู่นอกช่วง** (เก่ากว่าขั้นต่ำ หรือ **ใหม่กว่าที่ทดสอบ**):

| กรณี | ผลลัพธ์ |
|---|---|
| ลบจริง (live) | **ถูกบล็อก** — ไม่ลบอะไรเลย, log ผลเป็น `blocked` |
| Dry-Run / Preview | ทำได้ปกติ (ไม่มีการเขียน/ลบ) |
| การแจ้งเตือน | แสดงบนหน้าลบบัญชี, หน้ายืนยัน Reset, และหน้า Tools → Tutor User Cleanup |

**ช่องทาง Override** (สำหรับแอดมินที่ตรวจสอบแล้วว่า data model ยังเหมือนเดิม):
- ตั้งค่า **Version override** ในหน้า settings, หรือ
- ใช้ filter: `add_filter( 'tutor_user_cleanup_allow_unsupported_version', '__return_true' );`

ตรวจ status ผ่าน `Tutor_User_Cleanup_Tutor_Data_Repository::get_version_support_status()` ซึ่งคืนค่า `ok` / `too_old` / `too_new` / `unknown` — เมื่อยืนยันความเข้ากันได้กับเวอร์ชันใหม่แล้ว ให้ bump ค่า `TUTOR_USER_CLEANUP_TESTED_TUTOR_VERSION`

> หมายเหตุ: data model ที่ปลั๊กอินนี้อ้างอิง มาจากซอร์ส `master` ปัจจุบัน (สาย 4.x) — `tutor_enrolled`, `tutor_quiz_attempts`, `tutor_quiz_attempt_answers`, comment `course_completed`, usermeta `_tutor_completed_lesson_id_*` ยังคงเดิมในเวอร์ชันนี้
