package com.zyrotechbd.desk.ui.navigation

object Routes {
    const val Dashboard = "dashboard"
    const val Customers = "customers"
    const val Collect = "collect"            // customer search that goes straight to the bill form
    const val Bills = "bills"
    const val Printers = "printers"
    const val Password = "password"
    const val Inbox = "inbox"
    const val Monitoring = "monitoring"
    const val Calls = "calls"
    const val DialPad = "dial"
    const val More = "more"                  // printers, company, language, sign out

    const val CustomerDetail = "customers/{headerId}"
    fun customerDetail(headerId: Long) = "customers/$headerId"

    const val Tickets = "tickets"
    const val Ticket = "tickets/{ticketId}"
    fun ticket(id: Long) = "tickets/$id"
    const val NewTicket = "ticket-new?headerId={headerId}"
    fun newTicket(headerId: Long? = null) = "ticket-new" + (headerId?.let { "?headerId=$it" } ?: "")

    const val MyJobs = "my-jobs"
    const val Job = "my-jobs/{ticketId}"
    fun job(id: Long) = "my-jobs/$id"

    const val Call = "calls/{callId}"
    fun call(id: Long) = "calls/$id"

    const val Chat = "chats/{chatId}"
    fun chat(id: Long) = "chats/$id"

    const val BillForm = "bill/{headerId}"
    fun billForm(headerId: Long) = "bill/$headerId"
}
