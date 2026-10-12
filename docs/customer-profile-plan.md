# কাস্টমার প্রোফাইল: বিলিংয়ের সব ট্যাব ডেস্কে (প্ল্যান)

তারিখ: 2026-10-12। অবস্থা: **প্ল্যান, Zisan-এর অনুমোদনের অপেক্ষায়। এখনো কিছু বানানো হয়নি।**

লক্ষ্য: বিলিংয়ের `/customer/details?id={CustomerHeaderId}` পেজের ১১টা ট্যাবের সব তথ্য ডেস্কের নিজের DB-তে রাখা, আর ডেস্কের কাস্টমার পেজে একই রকম ট্যাবে দেখানো, যাতে স্টাফকে বিলিংয়ে যেতে না হয়।

অডিট কীভাবে: ২০২৬-১০-১২-এ engine-এর শেয়ার করা বিলিং সেশন দিয়ে (নতুন লগইন ছাড়া) শুধু GET। নমুনা কাস্টমার ID 0015 (header 15), আর ২৫ জন র‍্যান্ডম কাস্টমার সময় মাপার জন্য। কোনো কিছু লেখা/বদলানো হয়নি।

---

## ১. বিলিংয়ের ট্যাব আর তাদের উৎস

| # | বিলিং ট্যাব | উৎস (লগইন সেশনে) | কী আসে | গতি (প্রতি কাস্টমার) |
|---|---|---|---|---|
| 1 | Service Information | details পেজের HTML (সার্ভারে রেন্ডার) | Package, Profile, Joining Date, Client Type, Billing Start Month, Expire Date (দিন), Monthly Bill, Balance Due, Reference By, Connection Setup By, Last Log in; Installation Charge (তারিখ, Fee, Paid, Due, Method, Payment Date, Received By) | পেজ ~৪২০ KB |
| 2 | Network & Product | একই HTML | Server, Protocol, Zone, Sub Zone, Box, Connection Type, Latitude, Longitude, Cable (মিটার), Fiber Code, Number of Core, Device, Device MAC/Serial, Vendor, Purchase Date | একই পেজ |
| 3 | Personal Information | একই HTML | Father/Mother Name, Date of Birth, NID, Reg/SignUp Form No, Email, Gender, Occupation, Mobile, Phone, Facebook, Linkedin, District, Upazila, Road, House, Present/Permanent Address | একই পেজ |
| 4 | Generated & Updated Bills | `GET /BillTransaction/AjaxCusomerTransactionData/{h}` (paging নেই, সব একবারে) | BillMasterId, BillPeriodId/Year/Month, MonthName, CreationDate, Package, Speed, BillAmount, Discount, Due, PaidAmount, Status, ProcessedBy, Remark, IsEditable, `childs` = প্রতিটা বিলের এডিট-ইতিহাস | **~২ সেকেন্ড** (ধীর) |
| 5 | Received Bill History | `GET /Customer/AjaxReceivedHistory/{h}` (rows `data`-তে, `aaData` নয়) | BillHeaderId, BillReceivedDate, PaymentDate, UpdatedDate, Package, PayabaleBill, PaidAmount, Discount, BalanceDue, BillMonth, ReceivedBy, CreatedBy, UpdatedBy (approve করেছেন), Remarks, ReceiptOrTransactionNo, MoneyReceiptNo, PaymentMethodName, IsRealPayment (অনলাইন গেটওয়ে), IsBillReceiveCanceled; প্রতি সারিতে কাস্টমারের মোট TotalGeneratedBill/TotalPaidAmount/TotalDiscount | ~০.১ সে |
| 6 | Complain History | `GET /Customer/AjaxCompainHistory?id={h}` | ComplainId (= আমাদের `billing_tickets.complain_id`), Problem, ProblemPriorityId, CreationDate, CreatedBy, Status (0 খোলা, 1 সমাধান), SolvedBy[], SolvedOn, CommentsCounter | ~০.০৩ সে |
| 7 | Remarks History | `GET /Customer/AjaxRemarksHistory?id={h}` | RemarksType (যেমন EffectiveToChange), Comment, CreatedOn, CreatedBy। নিজস্ব id নেই | ~০.০৩ সে |
| 8 | Message History | `GET /Customer/AjaxMessagesHistory?id={h}` | SMSLogId, smsType, smsTypeName, smsText, date (শুধু দিন), status, sentBy। ID 0015-এর ১৭৫টা | ~০.১ সে |
| 9 | Customer Change Log | `GET /Customer/AjaxCustomerChangeLog?customerHeadId={h}&fromDate=&toDate=` (তারিখ ফাঁকা = সব) | প্রতিটা বদলের পর পুরো স্ন্যাপশট: ChangedBy, ChangedDate, Service, BillingStatus, MikrotikStatus, Server, Speed (MikroTik profile), Package, Protocol, ConnectionType, CustomerType, MonthlyBill, ExpireDate, ExtendedDate, Zone/SubZone/Box, Device, Mobile, DOB, Email, Gender, District, Thana, Address, Road, House, Remarks, EToRemarks, **Password (plaintext)**। নিজস্ব id নেই | ~০.০৬ সে |
| 10 | Enable/Disable Log | `GET /Customer/AjaxCustomerEnableDisableLog?cusId={h}&fromDate=&toDate=` | ChangedBy, ChangedDate, MikrotikStatus (Enable/Disable), Remarks (যেমন "Enabled Customer After Successful Payment"), **Password (plaintext)**। নিজস্ব id নেই | ~০.০৬ সে |
| 11 | Product & Service Sales Invoices | `GET /Customer/AjaxServiceAndProductInvoices?customerHeadId={h}` | InvoiceId, InvoiceNo, InvoiceType (ServiceInvoice/ProductInvoice), TotalAmount, PaidAmount, DiscountAmount, DueAmount, PaymentDueDate, SpecialNote (টাকা "2,300.00" স্ট্রিং) | ~০.০৩ সে; খুব কম কাস্টমারের থাকে (২৫ জনে ১ জন) |

কোম্পানি-জুড়ে (তারিখ দিয়ে) যেগুলো পাওয়া গেছে, তাই প্রতি কাস্টমার ঘুরতে হয় না:
- SMS: `GET /Report/AjaxTextMessages?fromDate=dd-mm-yyyy&toDate=dd-mm-yyyy` (একদিনে ~২৫০টা) — SMSLogId, SMSTypeName, SMSText, SendingDate (সময়সহ), UserId (কাস্টমারের header id), UserType, Status, SendBy। একই SMSLogId।
- টিকিট: `engine/ticket_sync.py` আগে থেকেই চালু (খোলা সব + গত ৯০ দিনের সমাধান)।
- বিল নেওয়া: `engine/daily_bill_sync.py` আগে থেকেই (`billing_daily_bills`, ১০ মিনিট পরপর)।
- Change log / Enable-Disable / Remarks / Bills-এর কোম্পানি-জুড়ে কোনো তালিকা পাওয়া যায়নি (ActivityLogger আছে, কিন্তু ফাঁকা দিল; কাজে লাগবে না ধরে নিচ্ছি)।

দুটো জিনিস যা জানা দরকার ছিল:
- Received history-র `BillHeaderId` আর আমাদের `billing_daily_bills.daily_bill_id` **একই নয়** (যেমন daily 169741 ↔ receipt 211214)। মেলাতে হবে কাস্টমার + দিন + টাকা দিয়ে, যেভাবে `bills._match_receipt` আগে থেকেই করে।
- Received history-তে শুধু approve হওয়া রিসিট আসে। approve-এর আগের বিল আমাদের `billing_daily_bills`/`bill_collections`-এ থাকে।

## ২. ডেস্কে এখন যা আছে

| ট্যাব | ডেস্কে আছে? |
|---|---|
| Service / Network / Personal | আংশিক। `billing_customers` (রাতের sync, `AjaxCustomerList` + `AjaxCustomerBillList`): package, monthly_bill, due, bill_day, joined_on, registered_on, zone/subzone/box, server, protocol, connection_type, customer_type, mobile, email, nid, address, house, road, thana, district, device, assigned_employee, `extra` jsonb। **নেই**: Profile, Billing Start Month, Reference By, Setup By, Last Login, Installation Charge, Father/Mother, DOB, Gender, Occupation, Phone, Facebook/Linkedin, Upazila, Present/Permanent Address, Form No, Cable, Core, Vendor, Purchase Date। `latitude/longitude/fiber_code/device_mac/details_fetched_at` কলাম আছে কিন্তু কেউ ভরে না (সব NULL)। |
| Generated Bills | নেই |
| Received History | আংশিক: `billing_daily_bills` শুধু ১ অক্টোবর ২০২৬ থেকে, শুধু Cash (১,৫৯৪ সারি); অনলাইন গেটওয়ে পেমেন্ট আর পুরনো ইতিহাস নেই। `last_payment_date` শুধু শেষ তারিখ। |
| Complain History | আংশিক: `billing_tickets` ৯ জুলাই ২০২৬ থেকে (২,০৬৩টা)। ID 0015-এর ১০টা টিকিটের একটাও নেই (সব পুরনো)। |
| Remarks, SMS, Change Log, Enable/Disable, Invoices | নেই। (`line_enables` শুধু ডেস্ক/বট থেকে চালু করার রেকর্ড; `billing_customer_changes` শুধু Active↔Left বদল।) |

## ৩. DB ডিজাইন

সব টেবিলে `company_id` (cascade), কাস্টমার মেলে `(company_id, customer_header_id)` দিয়ে, যেমন বাকি টেবিলে। টাকা `decimal(12,2)`, সময় UTC (বিলিংয়ের ঢাকা সময় থেকে রূপান্তর, যেমন `daily_bill_sync`)।

**পাসওয়ার্ড নিয়ম:** Change Log আর Enable/Disable Log প্রতি সারিতে PPPoE পাসওয়ার্ড plaintext দেয়। parse করার সময়েই `Password` (আর `pass` নামের যেকোনো key) ফেলে দেওয়া হবে, কোথাও (টেবিল, jsonb, লগ, ক্যাশ) রাখা হবে না। পাসওয়ার্ড বদলেছে কিনা শুধু মেমোরিতে তুলনা করে `password_changed` true/false রাখা হবে, মান নয়। টেস্টে চেক থাকবে যে কোনো সংরক্ষিত JSON-এ "Password" নেই। PPPoE পাসওয়ার্ড দেখার একমাত্র পথ আগের মতোই owner/admin বাটন (`customer_password_views` লগসহ)।

### ৩.১ `billing_customers`-এ নতুন কলাম (details পেজের HTML থেকে)
যেগুলো খোঁজা/ফিল্টারে লাগতে পারে সেগুলো কলাম, বাকিটা `details` jsonb-তে:
- `mikrotik_profile`, `billing_start_month` (date), `reference_by`, `setup_by`, `last_login_at` (timestamp), `gender`, `birth_date`, `father_name`, `mother_name`, `occupation`, `phone`, `upazila`, `present_address`, `permanent_address`, `form_no`
- আগে থেকে থাকা `latitude`, `longitude`, `fiber_code`, `device_mac`, `email`, `nid` এবার ভরবে
- `details` jsonb: facebook, linkedin, cable_meters, core_count, vendor, device_purchase_date, billing_created_on, installation {charged_on, fee, paid, due, method, paid_on, received_by}
- `details_fetched_at` (আছে) = শেষ কবে পেজ পড়া হয়েছে
- রাতের পুরো sync এই কলামগুলো মুছবে না (UPSERT-এর তালিকায় এগুলো থাকবে না)

### ৩.২ নতুন টেবিল

| টেবিল | মূল কলাম | unique |
|---|---|---|
| `billing_customer_bills` (Generated Bills) | customer_header_id, bill_master_id, bill_period_id, period_year, period_month, month_name, created_on, package, speed, bill_amount, discount, paid, due, status, processed_by, remark, is_editable, `versions` jsonb (childs = এডিট-ইতিহাস), synced_at, removed_at (বিলিংয়ে আর না থাকলে, মুছি না) | (company_id, bill_master_id) |
| `billing_customer_payments` (Received History) | customer_header_id, bill_header_id, received_on, entered_on, approved_at, bill_month, package, payable, paid, discount, balance_due, method, method_short, is_online, is_canceled, received_by, created_by, approved_by, remarks, trx_no, money_receipt_no, daily_bill_id (আমাদের `billing_daily_bills`-এর সাথে মেলানো, nullable), synced_at | (company_id, bill_header_id) |
| `billing_customer_remarks` | customer_header_id, remarks_type, comment, created_at, created_by, row_hash | (company_id, customer_header_id, row_hash) |
| `billing_customer_sms` | customer_header_id, sms_log_id, sms_type, type_name, text, sent_at (কোম্পানি-জুড়ে তালিকা থেকে সময়সহ, নইলে দিন), status, sent_by | (company_id, sms_log_id) |
| `billing_customer_change_logs` | customer_header_id, changed_at, changed_by, remarks, `snapshot` jsonb (পাসওয়ার্ড ছাড়া সব ঘর), `changed` jsonb (আগের স্ন্যাপশটের সাথে তুলনা করে শুধু যা বদলেছে: {field: [আগে, পরে]}), password_changed bool, row_hash | (company_id, customer_header_id, row_hash) |
| `billing_customer_line_logs` (Enable/Disable) | customer_header_id, changed_at, changed_by, action (enable/disable), remarks, row_hash | (company_id, customer_header_id, row_hash) |
| `billing_customer_invoices` | customer_header_id, invoice_id, invoice_no, invoice_type, total, paid, discount, due, due_date, special_note, synced_at | (company_id, invoice_id) |
| `billing_customer_fetches` (কোন অংশ কবে আনা হয়েছে) | customer_header_id, section (details/bills/payments/complains/remarks/sms/changes/line/invoices), fetched_at, rows, error | (company_id, customer_header_id, section) |

- নিজস্ব id নেই এমন লগে `row_hash` = সারির ঘরগুলোর sha1 (পাসওয়ার্ড বাদ দিয়ে), তাই বারবার আনলেও ডুপ্লিকেট হয় না।
- Complain History-র জন্য নতুন টেবিল নয়: একই ComplainId, তাই `billing_tickets`-এ পুরনো টিকিটগুলো যোগ হবে (`ON CONFLICT DO NOTHING`, `ticket_sync`-এর ভরা সারি ছোঁয়া হবে না), নতুন কলাম `source` = 'history'।
- আন্দাজ আকার: SMS সবচেয়ে বড়, ~৫-৬ লাখ সারি (ID 0015-এর ১৭৫টা × ~৫,৯৪০ জন, অনেকের কম), কয়েকশো MB; সার্ভারের ডিস্কে সমস্যা নয়। বিল ~৩-৪ লাখ, পেমেন্ট ~৩ লাখ, change log ~৩-৪ লাখ।

## ৪. Sync প্ল্যান

নতুন engine মডিউল `engine/customer_history.py`: প্রতিটা অংশের জন্য fetch + parse + UPSERT, সব শুধু পড়া (বিলিংয়ে কিছু লেখে না)।

নিয়ম (আগের অভিজ্ঞতা থেকে):
- engine আর timer-গুলোর একই শেয়ার করা বিলিং সেশন (`tenant.billing`), আলাদা লগইন নয়, `login()` নিজে থেকে ডাকা হবে না।
- এক সময়ে একটাই history-sync (lock ফাইল), একটার পর একটা request, ছোট বিরতি; লগইন এরর হলে থেমে যায়, পরের রানে যেখান থেকে থেমেছিল সেখান থেকে (`billing_customer_fetches` দেখে)।

**ক) পেজ খুললে (সবচেয়ে জরুরি):** ডেস্কে কাস্টমার পেজ খুললেই DB থেকে সাথে সাথে দেখায়। পেছনে engine `POST /internal/{c}/customers/{h}/history/refresh` সেই কাস্টমারের যেসব অংশ পুরনো (details ১ দিন, বাকি ১০ মিনিটের বেশি) সেগুলো আনে (~৩ সেকেন্ড, বিলের অংশের জন্য), পেজ কয়েক সেকেন্ড পরপর দেখে আপডেট করে, উপরে ছোট লেখা "বিলিং থেকে আপডেট হচ্ছে… / শেষ আপডেট ২ মিনিট আগে"। মনিটরিং পেজের on-demand sync-এর মতো।

**খ) প্রথমবার পুরো ব্যাকফিল:** `python -m engine.customer_history --backfill`, রাতে (বাংলাদেশ সময় রাত ১২টা-সকাল ৬টা) চলে, বাকি থাকলে পরের রাতে। প্রতি কাস্টমার ~৩-৪ সেকেন্ড (বিলের অংশ ~২ সে + details পেজ + বাকিগুলো), ~৫,৯৪০ জন ≈ ৬ ঘণ্টা, অর্থাৎ ১-২ রাত। আগে বর্তমান কাস্টমার, তারপর Left।

**গ) নিয়মিত:**
| অংশ | কখন |
|---|---|
| SMS | ৩০ মিনিট পরপর কোম্পানি-জুড়ে `/Report/AjaxTextMessages` আজ+গতকাল (প্রতি কাস্টমার ঘুরতে হয় না) |
| টিকিট | আগের মতো `ticket_sync` (৫ মিনিট) |
| পেমেন্ট | `daily_bill_sync` কোনো বিল approve হতে দেখলে সেই কাস্টমারের received history আবার আনে (MoneyReceiptNo সহ); আর রাতে সবার (দ্রুত, ~১০ মিনিট), যাতে অনলাইন গেটওয়ে পেমেন্টও আসে |
| Change log, Enable/Disable, Remarks, Invoices | রাতে সবার (রাত ৩টার কাস্টমার sync-এর পরে), দ্রুত endpoint, মোট ~২০-৩০ মিনিট |
| Details পেজ (Service/Network/Personal) | রাতের change log-এ যার নতুন এন্ট্রি এসেছে শুধু তার; নতুন কাস্টমার (`--new` sync) এলে তার; আর পেজ খুললে |
| Generated Bills | মাসের বিল তৈরি হওয়ার পরের রাতে সবার (ধীর, ~৩ ঘণ্টা, রাতে); মাঝে change log/পেমেন্টে বদল এলে বা পেজ খুললে সেই কাস্টমারের |

systemd timer: `zyro-customer-history.timer` (রাতে), `zyro-sms-sync.timer` (৩০ মিনিট)। লগ journal-এ, সারাংশ `billing_customer_syncs`-এর মতো একটা সারিতে।

## ৫. ডেস্কের কাস্টমার পেজ (ট্যাবসহ)

উপরে আগের মতো: পরিচয় কার্ড (নাম, ID, PPPoE, অবস্থা, বকেয়া) + লাইভ MikroTik/OLT বক্স + বাটন (লাইভ অবস্থা, PPPoE পাসওয়ার্ড owner/admin, টিকিট খুলুন, চ্যাট, বিল নিন)। তার নিচে বিলিংয়ের একই ক্রমে ট্যাব (Filament Tabs, প্রতিটা ট্যাব খুললে তবেই লোড, URL-এ `?tab=` তাই রিফ্রেশে একই ট্যাব থাকে):

1. **সার্ভিস** — প্যাকেজ, MikroTik প্রোফাইল, সংযোগের তারিখ, বিল শুরুর মাস, মেয়াদ (প্রতি মাসের দিন), মাসিক বিল, বকেয়া, রেফারেন্স, সংযোগ দিয়েছেন, শেষ লগইন; ইনস্টলেশন চার্জ কার্ড
2. **নেটওয়ার্ক ও ডিভাইস** — সার্ভার, প্রোটোকল, Zone / Sub Zone / Box, সংযোগের ধরন, লোকেশন (lat/long থাকলে Google Maps লিংক), কেবল মিটার, ফাইবার কোড, কোর, ডিভাইস, MAC, ভেন্ডর, কেনার তারিখ
3. **ব্যক্তিগত তথ্য** — পিতা/মাতা, জন্মতারিখ, NID, ফর্ম নং, ইমেইল, লিঙ্গ, পেশা, মোবাইল, ফোন, Facebook, Linkedin, জেলা, উপজেলা, রোড, বাসা, বর্তমান/স্থায়ী ঠিকানা
4. **বিল** — মাস, প্যাকেজ, স্পিড, বিল, ডিসকাউন্ট, জমা, বকেয়া, অবস্থা, কে তৈরি করেছে; সারিতে ক্লিক করলে এডিট-ইতিহাস
5. **পেমেন্ট** — তারিখ, মাস, টাকা, ডিসকাউন্ট, পদ্ধতি (অনলাইন হলে ব্যাজ), কে নিয়েছেন, কে approve করেছেন, রিসিট নং, মন্তব্য; উপরে মোট বিল / মোট জমা / মোট ডিসকাউন্ট; ডেস্ক থেকে নেওয়া বিলে "ডেস্ক" ব্যাজ আর রিসিট প্রিন্ট (আগের `print/receipt`); approve বাকি থাকা বিল উপরে আলাদা করে ("approve-এর অপেক্ষায়")
6. **অভিযোগ** — `billing_tickets` থেকে, সারিতে ক্লিক করলে আগের টিকিট ডিটেইলস মোডাল; "নতুন টিকিট" বাটন
7. **মন্তব্য** (Remarks)
8. **SMS** — ধরন, লেখা, সময়, অবস্থা, কে পাঠিয়েছেন; ধরন দিয়ে ফিল্টার
9. **পরিবর্তনের ইতিহাস** — প্রতি সারিতে শুধু যা বদলেছে "আগে → পরে" (যেমন প্যাকেজ, Zone, মাসিক বিল, MikroTik প্রোফাইল), কে, কখন; পাসওয়ার্ড বদলালে শুধু "PPPoE পাসওয়ার্ড বদলেছে" লেখা, মান নয়; তারিখ ফিল্টার
10. **লাইন চালু/বন্ধ** — বিলিংয়ের লগ আর ডেস্কের `line_enables` একসাথে সময় অনুযায়ী, ডেস্কেরগুলোতে "ডেস্ক/বট" ব্যাজ
11. **ইনভয়েস** — নং, ধরন, মোট, জমা, ডিসকাউন্ট, বকেয়া, শেষ তারিখ (খালি হলে ট্যাবে "নেই")

ফাঁকা ট্যাবে "বিলিংয়ে কিছু নেই" আর শেষ কবে দেখা হয়েছে। দেখার অনুমতি: কাস্টমার পেজ যারা দেখেন তারা সব ট্যাব (ডিফল্ট, নিচের প্রশ্ন ২ দেখুন)।

## ৬. কাজের ধাপ (অনুমোদনের পর)

1. Migration + `engine/customer_history.py` parser + পেজ-খুললে-refresh endpoint। ৩-৪ জন কাস্টমারের সব ট্যাব বিলিংয়ের পেজের সাথে মিলিয়ে দেখা (সারির সংখ্যা, মোট টাকা)।
2. ডেস্কের কাস্টমার পেজে ১১টা ট্যাব।
3. ব্যাকফিল (রাতে) + timer দুটো।
4. টেস্ট (পাসওয়ার্ড কোথাও সংরক্ষিত নেই সেটার টেস্টসহ), deploy (আগের নিয়মে config:cache ইত্যাদি), CONTEXT.md আপডেট।

## ৭. Zisan-এর সিদ্ধান্ত দরকার

1. **SMS ইতিহাস** কতদিনের রাখব? সব (প্রস্তাব, ডিস্কে সমস্যা নেই) / শেষ ১২ মাস।
2. **বিল আর পেমেন্ট ট্যাব** কে দেখবে? কাস্টমার পেজ যারা দেখেন সবাই (প্রস্তাব, বকেয়া এখনই সবাই দেখে) / শুধু owner-admin আর যাদের বিল কালেকশন মেনু আছে।
3. **ব্যাকফিলে Left কাস্টমার** (~১,১১০ জন)? হ্যাঁ, বর্তমানদের পরে (প্রস্তাব) / না, শুধু পেজ খুললে।
