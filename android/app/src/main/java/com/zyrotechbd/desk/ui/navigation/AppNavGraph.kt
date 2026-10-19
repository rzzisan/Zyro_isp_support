package com.zyrotechbd.desk.ui.navigation

import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.navigation.NavHostController
import androidx.navigation.NavType
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.navArgument
import com.zyrotechbd.desk.ui.screens.BillFormScreen
import com.zyrotechbd.desk.ui.screens.BillsScreen
import com.zyrotechbd.desk.ui.screens.CustomerDetailScreen
import com.zyrotechbd.desk.ui.screens.CustomersScreen
import com.zyrotechbd.desk.ui.screens.DashboardScreen
import com.zyrotechbd.desk.ui.screens.PrintersScreen

@Composable
fun AppNavGraph(navController: NavHostController, companyKey: String, modifier: Modifier = Modifier,
                more: @Composable () -> Unit) {
    NavHost(navController = navController, startDestination = Routes.Dashboard, modifier = modifier) {
        composable(Routes.Dashboard) { DashboardScreen(navController, companyKey) }
        composable(Routes.Customers) { CustomersScreen(navController, companyKey, collect = false) }
        composable(Routes.Collect) { CustomersScreen(navController, companyKey, collect = true) }
        composable(Routes.Bills) { BillsScreen(companyKey) }
        composable(Routes.Printers) { PrintersScreen() }
        composable(Routes.More) { more() }
        composable(Routes.CustomerDetail, arguments = listOf(navArgument("headerId") { type = NavType.LongType })) {
            CustomerDetailScreen(navController, companyKey, it.arguments?.getLong("headerId") ?: 0L)
        }
        composable(Routes.BillForm, arguments = listOf(navArgument("headerId") { type = NavType.LongType })) {
            BillFormScreen(navController, companyKey, it.arguments?.getLong("headerId") ?: 0L)
        }
    }
}
