package com.zyrotechbd.desk.ui.navigation

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.navigation.NavHostController
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.navArgument
import com.zyrotechbd.desk.ui.screens.BillFormScreen
import com.zyrotechbd.desk.ui.screens.DialPadScreen
import com.zyrotechbd.desk.ui.screens.ChangePasswordScreen
import com.zyrotechbd.desk.ui.screens.BillsScreen
import com.zyrotechbd.desk.ui.screens.ChatScreen
import com.zyrotechbd.desk.ui.screens.CustomerDetailScreen
import com.zyrotechbd.desk.ui.screens.CustomersScreen
import com.zyrotechbd.desk.ui.screens.DashboardScreen
import com.zyrotechbd.desk.ui.screens.InboxScreen
import com.zyrotechbd.desk.ui.screens.JobDetailScreen
import com.zyrotechbd.desk.ui.screens.MyJobsScreen
import com.zyrotechbd.desk.ui.screens.MonitoringScreen
import com.zyrotechbd.desk.ui.screens.CallsScreen
import com.zyrotechbd.desk.ui.screens.CallDetailScreen
import com.zyrotechbd.desk.ui.screens.NewTicketScreen
import com.zyrotechbd.desk.ui.screens.PrintersScreen
import com.zyrotechbd.desk.ui.screens.TicketDetailScreen
import com.zyrotechbd.desk.ui.screens.TicketsScreen
import com.zyrotechbd.desk.ui.screens.TeamChatScreen
import com.zyrotechbd.desk.ui.screens.TeamChatsScreen
import com.zyrotechbd.desk.ui.screens.WalkieScreen

@Composable
fun AppNavGraph(navController: NavHostController, companyKey: String, modifier: Modifier = Modifier,
                start: String = Routes.Dashboard, more: @Composable () -> Unit) {
    NavHost(navController = navController, startDestination = start, modifier = modifier) {
        composable(Routes.Dashboard) { DashboardScreen(navController, companyKey) }
        composable(Routes.Customers) { CustomersScreen(navController, companyKey, collect = false) }
        composable(Routes.Collect) { CustomersScreen(navController, companyKey, collect = true) }
        composable(Routes.Bills) { BillsScreen(companyKey) }
        composable(Routes.Printers) { PrintersScreen() }
        composable(Routes.Password) { ChangePasswordScreen(navController) }
        composable(Routes.More) { more() }
        composable(Routes.Inbox) { InboxScreen(navController, companyKey) }
        composable(Routes.Monitoring) { MonitoringScreen(navController, companyKey) }
        composable(Routes.Calls) { CallsScreen(navController, companyKey) }
        composable(Routes.DialPad) { DialPadScreen() }
        composable(Routes.Walkie) { WalkieScreen(companyKey) }
        composable(Routes.TeamChats) { TeamChatsScreen(navController, companyKey) }
        composable(Routes.TeamChat, arguments = listOf(navArgument("ref") { type = NavType.StringType })) {
            TeamChatScreen(companyKey, it.arguments?.getString("ref").orEmpty())
        }
        composable(Routes.Call, arguments = listOf(navArgument("callId") { type = NavType.LongType })) {
            CallDetailScreen(navController, companyKey, it.arguments?.getLong("callId") ?: 0L)
        }
        composable(Routes.Chat, arguments = listOf(navArgument("chatId") { type = NavType.LongType })) {
            ChatScreen(companyKey, it.arguments?.getLong("chatId") ?: 0L, navController)
        }
        composable(Routes.Tickets) { TicketsScreen(navController, companyKey) }
        composable(Routes.Ticket, arguments = listOf(navArgument("ticketId") { type = NavType.LongType })) {
            TicketDetailScreen(navController, companyKey, it.arguments?.getLong("ticketId") ?: 0L)
        }
        composable(Routes.MyJobs) { MyJobsScreen(navController, companyKey) }
        composable(Routes.Job, arguments = listOf(navArgument("ticketId") { type = NavType.LongType })) {
            JobDetailScreen(navController, companyKey, it.arguments?.getLong("ticketId") ?: 0L)
        }
        composable(Routes.NewTicket, arguments = listOf(navArgument("headerId") { type = NavType.LongType; defaultValue = 0L })) {
            NewTicketScreen(navController, companyKey, it.arguments?.getLong("headerId") ?: 0L)
        }
        composable(Routes.CustomerDetail, arguments = listOf(navArgument("headerId") { type = NavType.LongType })) {
            CustomerDetailScreen(navController, companyKey, it.arguments?.getLong("headerId") ?: 0L)
        }
        composable(Routes.BillForm, arguments = listOf(navArgument("headerId") { type = NavType.LongType })) {
            BillFormScreen(navController, companyKey, it.arguments?.getLong("headerId") ?: 0L)
        }
    }
}
