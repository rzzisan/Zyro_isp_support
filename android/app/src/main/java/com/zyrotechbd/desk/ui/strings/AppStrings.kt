package com.zyrotechbd.desk.ui.strings

/** Every text the app shows, in Bangla (default) and English. */
abstract class AppStrings {
    abstract val loginSubtitle: String
    abstract val loginHint: String
    abstract val emailLabel: String
    abstract val passwordLabel: String
    abstract val loginButton: String
    abstract val loginFailed: String
    abstract val noCompany: String
    abstract val langToggleLabel: String
    abstract val menu: String
    abstract val back: String
    abstract val close: String
    abstract val logout: String
    abstract val openDesk: String
    abstract val companies: String
    abstract val navDashboard: String
    abstract val navCustomers: String
    abstract val navCollect: String
    abstract val navBills: String
    abstract val navPrinters: String
    abstract val navBillsShort: String
    abstract val navMore: String
    abstract val language: String
    abstract val version: String
    abstract val titleCustomer: String
    abstract val titleBillForm: String
    abstract val findCustomer: String
    abstract val collectBill: String
    abstract val todayBills: String
    abstract val myCollection: String
    abstract val totalCollection: String
    abstract val approvePending: String
    abstract val approvePendingHint: String
    abstract val openTickets: String
    abstract val onlineNow: String
    abstract val waitingChats: String
    abstract val waitingChatsHint: String
    abstract val searchCustomer: String
    abstract val collectHint: String
    abstract val dueOnly: String
    abstract val allZones: String
    abstract val noCustomers: String
    abstract val noDue: String
    abstract val online: String
    abstract val offlineLabel: String
    abstract val lineOff: String
    abstract val blocked: String
    abstract val call: String
    abstract val due: String
    abstract val monthlyBill: String
    abstract val mobile: String
    abstract val packageLabel: String
    abstract val area: String
    abstract val address: String
    abstract val lastPayment: String
    abstract val billDay: String
    abstract val joined: String
    abstract val server: String
    abstract val liveStatus: String
    abstract val refresh: String
    abstract val routerNoAnswer: String
    abstract val onuNotFound: String
    abstract val status: String
    abstract val lastSeen: String
    abstract val distance: String
    abstract val lastDown: String
    abstract val billingState: String
    abstract val recentPayments: String
    abstract val noPayments: String
    abstract val liveFailed: String
    abstract val loadFailed: String
    abstract val offline: String
    abstract val retry: String
    abstract val billFormFailed: String
    abstract val billFormLoading: String
    abstract val dueNow: String
    abstract val billMonth: String
    abstract val blockedUnknown: String
    abstract val noDueInBilling: String
    abstract val paidLabel: String
    abstract val discountLabel: String
    abstract val methodLabel: String
    abstract val trxLabel: String
    abstract val remarksLabel: String
    abstract val remarksHint: String
    abstract val smsToCustomer: String
    abstract val smsNotReady: String
    abstract val nextBillingDate: String
    abstract val printAfterSave: String
    abstract val saveBill: String
    abstract val enterAmount: String
    abstract val overPayment: String
    abstract val pickMethod: String
    abstract val pickReceivedBy: String
    abstract val remarksRequired: String
    abstract val billNotSaved: String
    abstract val billSaved: String
    abstract val pendingBlocksHint: String
    abstract val printReceipt: String
    abstract val print: String
    abstract val done: String
    abstract val billingList: String
    abstract val myCollectionToday: String
    abstract val statusAll: String
    abstract val statusPending: String
    abstract val statusApproved: String
    abstract val statusDeleted: String
    abstract val noBills: String
    abstract val noBillsToday: String
    abstract val paperWidth: String
    abstract val paperHint: String
    abstract val printerKind: String
    abstract val printerClassic: String
    abstract val noPairedPrinters: String
    abstract val pairedDevicesLabel: String
    abstract val printerSelect: String
    abstract val printerSelected: String
    abstract val bleNotSupported: String
    abstract val scanBleBtn: String
    abstract val scanningLabel: String
    abstract val saveUuidsBtn: String
    abstract val saved: String
    abstract val grantPermissionBtn: String
    abstract val testPrint: String
    abstract val testPrintLine: String
    abstract val printingMsg: String
    abstract val printSuccessMsg: String
    abstract val bluetoothNotSupported: String
    abstract val noPrinterSelected: String
    abstract val bluetoothPermRequired: String
    abstract val bluetoothDisabled: String
    abstract val printerConnectFailed: String
    abstract val receiptLoadFailed: String
    abstract fun hello(name: String): String
    abstract fun todayAt(date: String): String
    abstract fun billCount(n: Int): String
    abstract fun ticketsToday(opened: Int, solved: Int): String
    abstract fun activeCustomers(n: Int): String
    abstract fun perMonth(amount: String): String
    abstract fun discountShort(amount: String): String
    abstract fun dueShort(amount: String): String
    abstract fun balanceLeft(amount: String): String
    abstract fun balanceAfter(payable: String, discount: String, paid: String, balance: String): String
    abstract val navInbox: String
    abstract val boxAll: String
    abstract val boxWaiting: String
    abstract val boxMine: String
    abstract val boxPaused: String
    abstract val boxStaff: String
    abstract val boxMuted: String
    abstract val searchChats: String
    abstract val noChats: String
    abstract val noName: String
    abstract val waitingLabel: String
    abstract val staffLabel: String
    abstract val botPausedLabel: String
    abstract val titleChat: String
    abstract val replyHint: String
    abstract val sendBtn: String
    abstract val windowClosed: String
    abstract val testMode: String
    abstract val sentTestMode: String
    abstract val botQuietFor: String
    abstract val noPause: String
    abstract val takeChat: String
    abstract val assignChat: String
    abstract val assignNobody: String
    abstract val assignedTo: String
    abstract val pauseBot: String
    abstract val resumeBot: String
    abstract val muteChat: String
    abstract val unmuteChat: String
    abstract val openCustomer: String
    abstract val attachFile: String
    abstract val removeFile: String
    abstract val senderBot: String
    abstract val senderCustomer: String
    abstract val imageLabel: String
    abstract val voiceLabel: String
    abstract val videoLabel: String
    abstract val fileLabel: String
    abstract val playLabel: String
    abstract val stopLabel: String
    abstract val openFile: String
    abstract val mediaFailed: String
    abstract val yesterday: String
    abstract val more: String
    abstract val chatActions: String
    abstract val navTickets: String
    abstract val titleTicket: String
    abstract val titleNewTicket: String
    abstract val tabOpen: String
    abstract val tabPending: String
    abstract val tabProcessing: String
    abstract val tabSolved: String
    abstract val searchTickets: String
    abstract val noTickets: String
    abstract val stateClosed: String
    abstract val categoryLabel: String
    abstract val priorityLabel: String
    abstract val departmentLabel: String
    abstract val employeesLabel: String
    abstract val assignedLabel: String
    abstract val solvedByLabel: String
    abstract val createdByLabel: String
    abstract val openedLabel: String
    abstract val solvedAtLabel: String
    abstract val durationLabel: String
    abstract val complainNoLabel: String
    abstract val descriptionLabel: String
    abstract val customerLabel: String
    abstract val assignTicket: String
    abstract val solveTicket: String
    abstract val solveRemark: String
    abstract val solveHint: String
    abstract val smsSolved: String
    abstract val smsOpen: String
    abstract val smsOff: String
    abstract val pickEmployees: String
    abstract val noDepartment: String
    abstract val ticketSolved: String
    abstract val ticketAssigned: String
    abstract val newTicket: String
    abstract val pickCustomer: String
    abstract val changeCustomer: String
    abstract val commentLabel: String
    abstract val commentHint: String
    abstract val pickCategory: String
    abstract val writeComment: String
    abstract val enterMobile: String
    abstract val createTicket: String
    abstract val ticketCreated: String
    abstract val openTicketWarn: String
    abstract val syncedAt: String
    abstract val loadMore: String
    abstract val cancel: String
    abstract val save: String
    abstract val today: String
    abstract val copied: String
    abstract val newMessagesBtn: String
    abstract val copyText: String
    abstract fun blockedFor(months: Int, days: Int): String
    abstract fun pendingWarning(amount: String, date: String, by: String): String
    abstract fun roleName(role: String?): String
}

object BengaliStrings : AppStrings() {
    override val loginSubtitle = "Desk-এর ইমেইল আর পাসওয়ার্ড দিয়ে ঢুকুন"
    override val loginHint = "desk.zyrotechbd.com-এর একই লগইন"
    override val emailLabel = "ইমেইল"
    override val passwordLabel = "পাসওয়ার্ড"
    override val loginButton = "লগইন"
    override val loginFailed = "লগইন হয়নি"
    override val noCompany = "আপনি কোনো কোম্পানির সদস্য নন"
    override val langToggleLabel = "English"
    override val menu = "মেনু"
    override val back = "পেছনে"
    override val close = "বন্ধ"
    override val logout = "লগআউট"
    override val openDesk = "ব্রাউজারে Desk খুলুন"
    override val companies = "কোম্পানি"
    override val navDashboard = "ড্যাশবোর্ড"
    override val navCustomers = "কাস্টমার"
    override val navCollect = "বিল নিন"
    override val navBills = "বিল কালেকশন"
    override val navPrinters = "প্রিন্টার"
    override val navBillsShort = "বিল"
    override val navMore = "আরও"
    override val language = "ভাষা"
    override val version = "সংস্করণ"
    override val titleCustomer = "কাস্টমার"
    override val titleBillForm = "বিল নিন"
    override val findCustomer = "কাস্টমার খুঁজুন"
    override val collectBill = "বিল নিন"
    override val todayBills = "আজকের বিল"
    override val myCollection = "আমার কালেকশন"
    override val totalCollection = "মোট কালেকশন"
    override val approvePending = "approve বাকি"
    override val approvePendingHint = "বিলিং-এ approve না হলে বকেয়া কমে না"
    override val openTickets = "খোলা টিকিট"
    override val onlineNow = "এখন অনলাইন"
    override val waitingChats = "উত্তরের অপেক্ষায় চ্যাট"
    override val waitingChatsHint = "চাপ দিয়ে ইনবক্স খুলুন"
    override val searchCustomer = "ID, মোবাইল, PPPoE বা নাম"
    override val collectHint = "যার বিল নেবেন তাকে খুঁজুন"
    override val dueOnly = "শুধু বকেয়া"
    override val allZones = "সব এলাকা"
    override val noCustomers = "কোনো কাস্টমার পাওয়া যায়নি"
    override val noDue = "বকেয়া নেই"
    override val online = "অনলাইন"
    override val offlineLabel = "অফলাইন"
    override val lineOff = "লাইন বন্ধ"
    override val blocked = "ব্লক"
    override val call = "কল"
    override val due = "বকেয়া"
    override val monthlyBill = "মাসিক বিল"
    override val mobile = "মোবাইল"
    override val packageLabel = "প্যাকেজ"
    override val area = "এলাকা"
    override val address = "ঠিকানা"
    override val lastPayment = "শেষ পেমেন্ট"
    override val billDay = "বিলের তারিখ"
    override val joined = "সংযোগের তারিখ"
    override val server = "সার্ভার"
    override val liveStatus = "লাইভ অবস্থা"
    override val refresh = "রিফ্রেশ"
    override val routerNoAnswer = "রাউটার থেকে উত্তর আসেনি"
    override val onuNotFound = "ONU পাওয়া যায়নি"
    override val status = "অবস্থা"
    override val lastSeen = "শেষ দেখা"
    override val distance = "দূরত্ব"
    override val lastDown = "শেষ ডাউন"
    override val billingState = "বিলিং"
    override val recentPayments = "সাম্প্রতিক পেমেন্ট"
    override val noPayments = "কোনো পেমেন্ট নেই"
    override val liveFailed = "লাইভ তথ্য আনা যায়নি"
    override val loadFailed = "লোড হয়নি"
    override val offline = "ইন্টারনেট নেই বা সার্ভারে পৌঁছানো যাচ্ছে না"
    override val retry = "আবার চেষ্টা"
    override val billFormFailed = "বিলিং থেকে ফর্ম আনা যায়নি"
    override val billFormLoading = "বিলিং থেকে ফর্ম আনছি..."
    override val dueNow = "বকেয়া (বিলিং-এ এখন)"
    override val billMonth = "বিলের মাস"
    override val blockedUnknown = "লাইন ব্লক (কবে থেকে বিলিংয়ের লগে পাওয়া যায়নি)"
    override val noDueInBilling = "বিলিং-এ কোনো বকেয়া নেই।"
    override val paidLabel = "টাকা নিলেন"
    override val discountLabel = "ডিসকাউন্ট"
    override val methodLabel = "মাধ্যম"
    override val trxLabel = "Trx নম্বর"
    override val remarksLabel = "নোট"
    override val remarksHint = "আংশিক টাকা বা ডিসকাউন্ট হলে লিখতে হবে"
    override val smsToCustomer = "কাস্টমারকে SMS"
    override val smsNotReady = "Desk সেটিংস → SMS গেটওয়ে-তে টেমপ্লেট সেট নেই"
    override val nextBillingDate = "পরের বিলিং তারিখ সেট"
    override val printAfterSave = "জমার পর রিসিট প্রিন্ট"
    override val saveBill = "বিল জমা দিন"
    override val enterAmount = "টাকা বা ডিসকাউন্ট অন্তত একটা দিন"
    override val overPayment = "বকেয়ার চেয়ে বেশি নেওয়া যাবে না"
    override val pickMethod = "মাধ্যম বাছুন"
    override val pickReceivedBy = "Received By বাছুন"
    override val remarksRequired = "আংশিক টাকা বা ডিসকাউন্টে নোট লিখতে হবে"
    override val billNotSaved = "বিল নেওয়া হয়নি"
    override val billSaved = "বিল জমা হয়েছে, বিলিং-এ approve বাকি"
    override val pendingBlocksHint = "approve না হওয়া পর্যন্ত এই কাস্টমারের নতুন বিল নেওয়া যাবে না"
    override val printReceipt = "রিসিট প্রিন্ট"
    override val print = "প্রিন্ট"
    override val done = "শেষ"
    override val billingList = "বিলিং-এর লিস্ট"
    override val myCollectionToday = "আজ আমি নিয়েছি"
    override val statusAll = "সব"
    override val statusPending = "approve বাকি"
    override val statusApproved = "approved"
    override val statusDeleted = "ডিলিট"
    override val noBills = "এই দিনে কোনো বিল নেই"
    override val noBillsToday = "আজ এখনো কোনো বিল নেননি"
    override val paperWidth = "কাগজের মাপ"
    override val paperHint = "Desk-এর রিসিট সেটিংসের একই রিসিট প্রিন্ট হবে"
    override val printerKind = "প্রিন্টারের ধরন"
    override val printerClassic = "Bluetooth (সাধারণ)"
    override val noPairedPrinters = "কোনো pair করা প্রিন্টার নেই। ফোনের Bluetooth সেটিংসে আগে pair করুন।"
    override val pairedDevicesLabel = "pair করা ডিভাইস"
    override val printerSelect = "বাছুন"
    override val printerSelected = "বাছা হয়েছে"
    override val bleNotSupported = "এই ফোনে BLE নেই"
    override val scanBleBtn = "BLE প্রিন্টার খুঁজুন"
    override val scanningLabel = "খুঁজছি..."
    override val saveUuidsBtn = "UUID সেভ"
    override val saved = "সেভ হয়েছে"
    override val grantPermissionBtn = "অনুমতি দিন"
    override val testPrint = "টেস্ট প্রিন্ট"
    override val testPrintLine = "প্রিন্টার ঠিক আছে"
    override val printingMsg = "প্রিন্ট হচ্ছে..."
    override val printSuccessMsg = "প্রিন্ট হয়েছে"
    override val bluetoothNotSupported = "এই ফোনে Bluetooth নেই"
    override val noPrinterSelected = "প্রিন্টার বাছা হয়নি: মেনু → প্রিন্টার"
    override val bluetoothPermRequired = "Bluetooth-এর অনুমতি লাগবে"
    override val bluetoothDisabled = "ফোনের Bluetooth চালু করুন"
    override val printerConnectFailed = "প্রিন্টারে সংযোগ হয়নি। প্রিন্টার চালু আছে কিনা দেখুন।"
    override val receiptLoadFailed = "রিসিট আনা যায়নি"
    override fun hello(name: String): String = "স্বাগতম, $name"
    override fun todayAt(date: String): String = "আজ $date"
    override fun billCount(n: Int): String = "$n টি বিল"
    override fun ticketsToday(opened: Int, solved: Int): String = "আজ খোলা $opened, সমাধান $solved"
    override fun activeCustomers(n: Int): String = "$n সক্রিয় কাস্টমার"
    override fun perMonth(amount: String): String = "৳$amount/মাস"
    override fun discountShort(amount: String): String = "ডিস. ৳$amount"
    override fun dueShort(amount: String): String = "বাকি ৳$amount"
    override fun balanceLeft(amount: String): String = "বাকি বকেয়া $amount টাকা"
    override fun balanceAfter(payable: String, discount: String, paid: String, balance: String): String = "বকেয়া $payable − ডিসকাউন্ট $discount − নিলেন $paid = বাকি $balance"
    override fun blockedFor(months: Int, days: Int): String = "লাইন ব্লক $months মাস $days দিন ধরে"
    override fun pendingWarning(amount: String, date: String, by: String): String = "এই কাস্টমারের $amount টাকার একটা বিল ($date, $by) এখনো approve হয়নি। approve না হওয়া পর্যন্ত বকেয়া কমে না।"
    override fun roleName(role: String?): String = when (role) {
        "owner" -> "মালিক"
        "admin" -> "অ্যাডমিন"
        "agent" -> "এজেন্ট"
        else -> role ?: ""
    }
    override val navInbox = "ইনবক্স"
    override val boxAll = "সব"
    override val boxWaiting = "উত্তরের অপেক্ষায়"
    override val boxMine = "আমার"
    override val boxPaused = "বট থামানো"
    override val boxStaff = "স্টাফ"
    override val boxMuted = "নোটিফিকেশন বন্ধ"
    override val searchChats = "নাম, কাস্টমার ID বা নম্বর"
    override val noChats = "কোনো চ্যাট নেই"
    override val noName = "নাম নেই"
    override val waitingLabel = "উত্তর দিন"
    override val staffLabel = "স্টাফ"
    override val botPausedLabel = "বট থামানো"
    override val titleChat = "চ্যাট"
    override val replyHint = "মেসেজ লিখুন"
    override val sendBtn = "পাঠান"
    override val windowClosed = "কাস্টমারের শেষ মেসেজ ২৪ ঘণ্টার বেশি আগে, তাই এখন সাধারণ মেসেজ যাবে না।"
    override val testMode = "টেস্ট মোড: মেসেজ সেভ হবে, কাস্টমারের কাছে যাবে না।"
    override val sentTestMode = "টেস্ট মোড: সেভ হয়েছে, কাস্টমারের কাছে যায়নি"
    override val botQuietFor = "বট চুপ থাকবে"
    override val noPause = "চুপ থাকবে না"
    override val takeChat = "আমি নিলাম"
    override val assignChat = "অন্যকে দিন"
    override val assignNobody = "কেউ না"
    override val assignedTo = "দায়িত্বে"
    override val pauseBot = "বট থামান (১২ ঘণ্টা)"
    override val resumeBot = "বট চালু করুন"
    override val muteChat = "নোটিফিকেশন বন্ধ"
    override val unmuteChat = "নোটিফিকেশন চালু"
    override val openCustomer = "কাস্টমার পাতা"
    override val attachFile = "ফাইল যোগ করুন"
    override val removeFile = "বাদ দিন"
    override val senderBot = "বট"
    override val senderCustomer = "কাস্টমার"
    override val imageLabel = "ছবি"
    override val voiceLabel = "ভয়েস"
    override val videoLabel = "ভিডিও"
    override val fileLabel = "ফাইল"
    override val playLabel = "শুনুন"
    override val stopLabel = "থামান"
    override val openFile = "খুলুন"
    override val mediaFailed = "মিডিয়া খোলা যায়নি"
    override val yesterday = "গতকাল"
    override val more = "আরও"
    override val chatActions = "চ্যাটের কাজ"
    override val navTickets = "টিকিট"
    override val titleTicket = "টিকিট"
    override val titleNewTicket = "নতুন টিকিট"
    override val tabOpen = "খোলা"
    override val tabPending = "অপেক্ষমাণ"
    override val tabProcessing = "কাজ চলছে"
    override val tabSolved = "সমাধান"
    override val searchTickets = "টিকিট নং, নাম, ID, মোবাইল"
    override val noTickets = "কোনো টিকিট নেই"
    override val stateClosed = "বন্ধ"
    override val categoryLabel = "সমস্যা"
    override val priorityLabel = "প্রায়োরিটি"
    override val departmentLabel = "ডিপার্টমেন্ট"
    override val employeesLabel = "কর্মী"
    override val assignedLabel = "দায়িত্বে"
    override val solvedByLabel = "সমাধান করেছে"
    override val createdByLabel = "খুলেছে"
    override val openedLabel = "খোলা হয়েছে"
    override val solvedAtLabel = "সমাধান হয়েছে"
    override val durationLabel = "সময় লেগেছে"
    override val complainNoLabel = "অভিযোগের নম্বর"
    override val descriptionLabel = "সমস্যার বিবরণ"
    override val customerLabel = "কাস্টমার"
    override val assignTicket = "কর্মী দিন"
    override val solveTicket = "সমাধান করুন"
    override val solveRemark = "সমাধানের নোট"
    override val solveHint = "বিলিং আবার দেখে নেবে লাইন MikroTik-এ অনলাইন কিনা"
    override val smsSolved = "কাস্টমারকে সমাধানের SMS"
    override val smsOpen = "কাস্টমারকে টিকিটের SMS"
    override val smsOff = "SMS সেটিংস চালু নেই"
    override val pickEmployees = "অন্তত একজন কর্মী বেছে নিন"
    override val noDepartment = "কোনোটা না"
    override val ticketSolved = "টিকিট সমাধান হয়েছে"
    override val ticketAssigned = "কর্মী দেওয়া হয়েছে"
    override val newTicket = "টিকিট খুলুন"
    override val pickCustomer = "কাস্টমার বেছে নিন"
    override val changeCustomer = "বদলান"
    override val commentLabel = "সমস্যা লিখুন"
    override val commentHint = "কী সমস্যা, কখন থেকে"
    override val pickCategory = "সমস্যার ধরন বেছে নিন"
    override val writeComment = "সমস্যা লিখুন"
    override val enterMobile = "মোবাইল নম্বর লিখুন"
    override val createTicket = "টিকিট খুলুন"
    override val ticketCreated = "টিকিট খোলা হয়েছে"
    override val openTicketWarn = "এই কাস্টমারের একটা টিকিট এখনো খোলা আছে"
    override val syncedAt = "বিলিং থেকে আনা"
    override val loadMore = "আরো দেখুন"
    override val cancel = "বাতিল"
    override val save = "সেভ"
    override val today = "আজ"
    override val copied = "কপি হয়েছে"
    override val newMessagesBtn = "নতুন মেসেজ"
    override val copyText = "কপি"
}

object EnglishStrings : AppStrings() {
    override val loginSubtitle = "Sign in with your Desk email and password"
    override val loginHint = "Same login as desk.zyrotechbd.com"
    override val emailLabel = "Email"
    override val passwordLabel = "Password"
    override val loginButton = "Sign in"
    override val loginFailed = "Sign-in failed"
    override val noCompany = "You are not a member of any company"
    override val langToggleLabel = "বাংলা"
    override val menu = "Menu"
    override val back = "Back"
    override val close = "Close"
    override val logout = "Sign out"
    override val openDesk = "Open Desk in browser"
    override val companies = "Companies"
    override val navDashboard = "Dashboard"
    override val navCustomers = "Customers"
    override val navCollect = "Collect bill"
    override val navBills = "Bill collection"
    override val navPrinters = "Printer"
    override val navBillsShort = "Bills"
    override val navMore = "More"
    override val language = "Language"
    override val version = "Version"
    override val titleCustomer = "Customer"
    override val titleBillForm = "Collect bill"
    override val findCustomer = "Find customer"
    override val collectBill = "Collect bill"
    override val todayBills = "Today's bills"
    override val myCollection = "My collection"
    override val totalCollection = "Total collection"
    override val approvePending = "Awaiting approval"
    override val approvePendingHint = "Due drops only after approval in billing"
    override val openTickets = "Open tickets"
    override val onlineNow = "Online now"
    override val waitingChats = "Chats waiting"
    override val waitingChatsHint = "Tap to open the inbox"
    override val searchCustomer = "ID, mobile, PPPoE or name"
    override val collectHint = "Find the customer to collect from"
    override val dueOnly = "Due only"
    override val allZones = "All zones"
    override val noCustomers = "No customers found"
    override val noDue = "No due"
    override val online = "Online"
    override val offlineLabel = "Offline"
    override val lineOff = "Line off"
    override val blocked = "Blocked"
    override val call = "Call"
    override val due = "Due"
    override val monthlyBill = "Monthly bill"
    override val mobile = "Mobile"
    override val packageLabel = "Package"
    override val area = "Area"
    override val address = "Address"
    override val lastPayment = "Last payment"
    override val billDay = "Bill day"
    override val joined = "Joined"
    override val server = "Server"
    override val liveStatus = "Live status"
    override val refresh = "Refresh"
    override val routerNoAnswer = "No answer from the router"
    override val onuNotFound = "ONU not found"
    override val status = "Status"
    override val lastSeen = "Last seen"
    override val distance = "Distance"
    override val lastDown = "Last down"
    override val billingState = "Billing"
    override val recentPayments = "Recent payments"
    override val noPayments = "No payments"
    override val liveFailed = "Could not load live info"
    override val loadFailed = "Could not load"
    override val offline = "No connection to the server"
    override val retry = "Retry"
    override val billFormFailed = "Could not load the billing form"
    override val billFormLoading = "Loading the form from billing..."
    override val dueNow = "Due (in billing now)"
    override val billMonth = "Bill month"
    override val blockedUnknown = "Line blocked (start not found in billing log)"
    override val noDueInBilling = "No due in billing."
    override val paidLabel = "Amount received"
    override val discountLabel = "Discount"
    override val methodLabel = "Method"
    override val trxLabel = "Trx no"
    override val remarksLabel = "Note"
    override val remarksHint = "Required for partial payment or discount"
    override val smsToCustomer = "SMS the customer"
    override val smsNotReady = "SMS gateway/template not set in Desk settings"
    override val nextBillingDate = "Set next billing date"
    override val printAfterSave = "Print receipt after saving"
    override val saveBill = "Save bill"
    override val enterAmount = "Enter an amount or a discount"
    override val overPayment = "Cannot take more than the due"
    override val pickMethod = "Pick a method"
    override val pickReceivedBy = "Pick Received By"
    override val remarksRequired = "A note is required for partial payment or discount"
    override val billNotSaved = "Bill not saved"
    override val billSaved = "Bill saved, awaiting approval in billing"
    override val pendingBlocksHint = "No new bill for this customer until the pending one is approved"
    override val printReceipt = "Print receipt"
    override val print = "Print"
    override val done = "Done"
    override val billingList = "Billing list"
    override val myCollectionToday = "Mine today"
    override val statusAll = "All"
    override val statusPending = "Pending"
    override val statusApproved = "Approved"
    override val statusDeleted = "Deleted"
    override val noBills = "No bills on this day"
    override val noBillsToday = "No bills collected today yet"
    override val paperWidth = "Paper width"
    override val paperHint = "Prints the same receipt as the Desk receipt settings"
    override val printerKind = "Printer type"
    override val printerClassic = "Bluetooth (classic)"
    override val noPairedPrinters = "No paired printers. Pair it first in the phone's Bluetooth settings."
    override val pairedDevicesLabel = "Paired devices"
    override val printerSelect = "Select"
    override val printerSelected = "Selected"
    override val bleNotSupported = "BLE not supported"
    override val scanBleBtn = "Scan BLE printers"
    override val scanningLabel = "Scanning..."
    override val saveUuidsBtn = "Save UUIDs"
    override val saved = "Saved"
    override val grantPermissionBtn = "Grant permission"
    override val testPrint = "Test print"
    override val testPrintLine = "Printer works"
    override val printingMsg = "Printing..."
    override val printSuccessMsg = "Printed"
    override val bluetoothNotSupported = "Bluetooth not supported"
    override val noPrinterSelected = "No printer selected: menu → Printer"
    override val bluetoothPermRequired = "Bluetooth permission required"
    override val bluetoothDisabled = "Turn on Bluetooth"
    override val printerConnectFailed = "Could not connect to the printer. Is it on?"
    override val receiptLoadFailed = "Could not load the receipt"
    override fun hello(name: String): String = "Hello, $name"
    override fun todayAt(date: String): String = "Today, $date"
    override fun billCount(n: Int): String = "$n bills"
    override fun ticketsToday(opened: Int, solved: Int): String = "Today: $opened opened, $solved solved"
    override fun activeCustomers(n: Int): String = "$n active customers"
    override fun perMonth(amount: String): String = "৳$amount/mo"
    override fun discountShort(amount: String): String = "disc. ৳$amount"
    override fun dueShort(amount: String): String = "due ৳$amount"
    override fun balanceLeft(amount: String): String = "Remaining due $amount tk"
    override fun balanceAfter(payable: String, discount: String, paid: String, balance: String): String = "Due $payable − discount $discount − paid $paid = left $balance"
    override fun blockedFor(months: Int, days: Int): String = "Line blocked for $months months $days days"
    override fun pendingWarning(amount: String, date: String, by: String): String = "A $amount tk bill of this customer ($date, $by) is not approved yet. The due drops only after approval."
    override fun roleName(role: String?): String = when (role) {
        "owner" -> "Owner"
        "admin" -> "Admin"
        "agent" -> "Agent"
        else -> role ?: ""
    }
    override val navInbox = "Inbox"
    override val boxAll = "All"
    override val boxWaiting = "Waiting"
    override val boxMine = "Mine"
    override val boxPaused = "Bot paused"
    override val boxStaff = "Staff"
    override val boxMuted = "Muted"
    override val searchChats = "Name, customer ID or number"
    override val noChats = "No chats"
    override val noName = "No name"
    override val waitingLabel = "Reply"
    override val staffLabel = "Staff"
    override val botPausedLabel = "Bot paused"
    override val titleChat = "Chat"
    override val replyHint = "Type a message"
    override val sendBtn = "Send"
    override val windowClosed = "The customer's last message is over 24 hours old, so a normal message cannot be sent now."
    override val testMode = "Test mode: messages are saved but not sent to the customer."
    override val sentTestMode = "Test mode: saved, not sent to the customer"
    override val botQuietFor = "Bot stays quiet"
    override val noPause = "No pause"
    override val takeChat = "I'll take it"
    override val assignChat = "Give to someone"
    override val assignNobody = "Nobody"
    override val assignedTo = "Assigned to"
    override val pauseBot = "Pause bot (12 hours)"
    override val resumeBot = "Resume bot"
    override val muteChat = "Mute notifications"
    override val unmuteChat = "Unmute notifications"
    override val openCustomer = "Customer page"
    override val attachFile = "Attach file"
    override val removeFile = "Remove"
    override val senderBot = "Bot"
    override val senderCustomer = "Customer"
    override val imageLabel = "Photo"
    override val voiceLabel = "Voice"
    override val videoLabel = "Video"
    override val fileLabel = "File"
    override val playLabel = "Play"
    override val stopLabel = "Stop"
    override val openFile = "Open"
    override val mediaFailed = "Could not open the media"
    override val yesterday = "Yesterday"
    override val more = "More"
    override val chatActions = "Chat actions"
    override val navTickets = "Tickets"
    override val titleTicket = "Ticket"
    override val titleNewTicket = "New ticket"
    override val tabOpen = "Open"
    override val tabPending = "Pending"
    override val tabProcessing = "In progress"
    override val tabSolved = "Solved"
    override val searchTickets = "Ticket no, name, ID, mobile"
    override val noTickets = "No tickets"
    override val stateClosed = "Closed"
    override val categoryLabel = "Problem"
    override val priorityLabel = "Priority"
    override val departmentLabel = "Department"
    override val employeesLabel = "Employees"
    override val assignedLabel = "Assigned to"
    override val solvedByLabel = "Solved by"
    override val createdByLabel = "Opened by"
    override val openedLabel = "Opened"
    override val solvedAtLabel = "Solved"
    override val durationLabel = "Time taken"
    override val complainNoLabel = "Complaint number"
    override val descriptionLabel = "Problem description"
    override val customerLabel = "Customer"
    override val assignTicket = "Assign"
    override val solveTicket = "Solve"
    override val solveRemark = "Solve note"
    override val solveHint = "Billing checks again that the line is online on MikroTik"
    override val smsSolved = "Solved SMS to customer"
    override val smsOpen = "Ticket SMS to customer"
    override val smsOff = "SMS is not set up"
    override val pickEmployees = "Pick at least one employee"
    override val noDepartment = "None"
    override val ticketSolved = "Ticket solved"
    override val ticketAssigned = "Employees assigned"
    override val newTicket = "Open ticket"
    override val pickCustomer = "Pick a customer"
    override val changeCustomer = "Change"
    override val commentLabel = "Describe the problem"
    override val commentHint = "What is wrong, since when"
    override val pickCategory = "Pick the problem type"
    override val writeComment = "Write the problem"
    override val enterMobile = "Enter a mobile number"
    override val createTicket = "Open ticket"
    override val ticketCreated = "Ticket opened"
    override val openTicketWarn = "This customer already has an open ticket"
    override val syncedAt = "From billing"
    override val loadMore = "Load more"
    override val cancel = "Cancel"
    override val save = "Save"
    override val today = "Today"
    override val copied = "Copied"
    override val newMessagesBtn = "New messages"
    override val copyText = "Copy"
}
