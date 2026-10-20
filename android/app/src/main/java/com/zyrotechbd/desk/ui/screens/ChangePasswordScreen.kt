package com.zyrotechbd.desk.ui.screens

import android.widget.Toast
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.navigation.NavHostController
import com.zyrotechbd.desk.data.network.ApiClient
import com.zyrotechbd.desk.data.network.PasswordRequest
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.DeskTextField
import com.zyrotechbd.desk.ui.components.Field
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.PrimaryButton
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Muted
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.launch

/** The signed-in user changes their own password (current one + new twice); this phone stays signed in. */
@Composable
fun ChangePasswordScreen(navController: NavHostController) {
    val s = LocalStrings.current
    val context = LocalContext.current
    val scope = rememberCoroutineScope()
    var current by remember { mutableStateOf("") }
    var new by remember { mutableStateOf("") }
    var confirm by remember { mutableStateOf("") }
    var saving by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val password = KeyboardOptions(keyboardType = KeyboardType.Password)

    Column(Modifier.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(16.dp)) {
        DeskCard {
            Column(Modifier.padding(16.dp)) {
                Field(s.currentPassword) {
                    DeskTextField(current, { current = it }, modifier = Modifier.fillMaxWidth(), keyboardOptions = password,
                        visualTransformation = PasswordVisualTransformation())
                }
                Spacer(Modifier.height(14.dp))
                Field(s.newPassword) {
                    DeskTextField(new, { new = it }, modifier = Modifier.fillMaxWidth(), keyboardOptions = password,
                        visualTransformation = PasswordVisualTransformation())
                }
                Spacer(Modifier.height(14.dp))
                Field(s.confirmPassword) {
                    DeskTextField(confirm, { confirm = it }, modifier = Modifier.fillMaxWidth(), keyboardOptions = password,
                        visualTransformation = PasswordVisualTransformation())
                }
                Spacer(Modifier.height(10.dp))
                Text(s.passwordHint, fontSize = 12.sp, color = Muted, modifier = Modifier.padding(start = 6.dp))
                error?.let {
                    Spacer(Modifier.height(14.dp))
                    MessageBox(it)
                }
                Spacer(Modifier.height(18.dp))
                PrimaryButton(s.changePassword, enabled = current.isNotEmpty() && new.isNotEmpty() && confirm.isNotEmpty(),
                    loading = saving) {
                    error = when {
                        new.length < 8 -> s.passwordTooShort
                        new != confirm -> s.passwordMismatch
                        else -> null
                    }
                    if (error != null) return@PrimaryButton
                    saving = true
                    scope.launch {
                        try {
                            ApiClient.service.changePassword(PasswordRequest(current, new, confirm))
                            Toast.makeText(context, s.passwordChanged, Toast.LENGTH_SHORT).show()
                            navController.popBackStack()
                        } catch (e: CancellationException) {
                            throw e
                        } catch (e: Exception) {
                            error = ApiClient.errorMessage(e, s.passwordFailed, s.offline)
                        } finally {
                            saving = false
                        }
                    }
                }
            }
        }
    }
}
