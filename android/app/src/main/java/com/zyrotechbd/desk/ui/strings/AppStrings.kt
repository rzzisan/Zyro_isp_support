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
    override val waitingChatsHint = "Desk-এর ইনবক্সে উত্তর দিন"
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
    override val waitingChatsHint = "Reply in the Desk inbox"
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
}
