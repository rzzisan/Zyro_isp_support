package com.zyrotechbd.desk.ui.navigation

object Routes {
    const val Dashboard = "dashboard"
    const val Customers = "customers"
    const val Collect = "collect"            // customer search that goes straight to the bill form
    const val Bills = "bills"
    const val Printers = "printers"
    const val Inbox = "inbox"
    const val Monitoring = "monitoring"
    const val More = "more"                  // printers, company, language, sign out

    const val CustomerDetail = "customers/{headerId}"
    fun customerDetail(headerId: Long) = "customers/$headerId"

    const val Tickets = "tickets"
    const val Ticket = "tickets/{ticketId}"
    fun ticket(id: Long) = "tickets/$id"
    const val NewTicket = "ticket-new?headerId={headerId}"
    fun newTicket(headerId: Long? = null) = "ticket-new" + (headerId?.let { "?headerId=$it" } ?: "")

    const val Chat = "chats/{chatId}"
    fun chat(id: Long) = "chats/$id"

    const val BillForm = "bill/{headerId}"
    fun billForm(headerId: Long) = "bill/$headerId"
}
