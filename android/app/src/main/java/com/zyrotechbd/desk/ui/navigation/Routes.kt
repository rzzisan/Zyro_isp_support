package com.zyrotechbd.desk.ui.navigation

object Routes {
    const val Dashboard = "dashboard"
    const val Customers = "customers"
    const val Collect = "collect"            // customer search that goes straight to the bill form
    const val Bills = "bills"
    const val Printers = "printers"
    const val More = "more"                  // printers, company, language, sign out

    const val CustomerDetail = "customers/{headerId}"
    fun customerDetail(headerId: Long) = "customers/$headerId"

    const val BillForm = "bill/{headerId}"
    fun billForm(headerId: Long) = "bill/$headerId"
}
