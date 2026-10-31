package com.zyrotechbd.desk.ui.screens

import androidx.compose.foundation.Image
import androidx.compose.ui.res.painterResource
import com.zyrotechbd.desk.R
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.imePadding
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.statusBarsPadding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Language
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.OutlinedTextFieldDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.zyrotechbd.desk.ui.components.DeskCard
import com.zyrotechbd.desk.ui.components.MessageBox
import com.zyrotechbd.desk.ui.components.PrimaryButton
import com.zyrotechbd.desk.ui.strings.LocalStrings
import com.zyrotechbd.desk.ui.theme.Background
import com.zyrotechbd.desk.ui.theme.Ink
import com.zyrotechbd.desk.ui.theme.InputLine
import com.zyrotechbd.desk.ui.theme.Muted
import com.zyrotechbd.desk.ui.theme.Primary
import com.zyrotechbd.desk.ui.theme.Surface

/** Sign-in, laid out like the Desk panel's own login page: a white card on the light gray page. */
@Composable
fun LoginScreen(
    email: String,
    password: String,
    isLoading: Boolean,
    errorMessage: String?,
    onEmailChange: (String) -> Unit,
    onPasswordChange: (String) -> Unit,
    onToggleLang: () -> Unit,
    onLogin: () -> Unit,
    privacyAccepted: Boolean = false,
    onPrivacyChange: (Boolean) -> Unit = {},
) {
    val s = LocalStrings.current
    var showPassword by remember { mutableStateOf(false) }
    val canLogin = !isLoading && email.isNotBlank() && password.isNotBlank() && privacyAccepted
    val fieldColors = OutlinedTextFieldDefaults.colors(
        focusedBorderColor = Primary,
        unfocusedBorderColor = InputLine,
        focusedContainerColor = Surface,
        unfocusedContainerColor = Surface,
        cursorColor = Primary
    )
    val fieldShape = RoundedCornerShape(8.dp)
    val fieldText = TextStyle(color = Ink, fontSize = 16.sp)

    Box(Modifier.fillMaxSize().background(Background)) {
        Column(
            modifier = Modifier.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(horizontal = 20.dp),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Spacer(Modifier.height(96.dp))
            Image(painterResource(R.drawable.ic_brand), contentDescription = null, modifier = Modifier.size(52.dp).clip(RoundedCornerShape(12.dp)))
            Spacer(Modifier.height(14.dp))
            Text("Zyro Desk", color = Ink, fontWeight = FontWeight.Bold, fontSize = 22.sp)
            Spacer(Modifier.height(24.dp))

            DeskCard {
                Column(Modifier.padding(20.dp)) {
                    Text(s.loginButton, color = Ink, fontWeight = FontWeight.SemiBold, fontSize = 18.sp)
                    Spacer(Modifier.height(2.dp))
                    Text(s.loginSubtitle, color = Muted, fontSize = 13.sp)
                    Spacer(Modifier.height(20.dp))
                    Text(s.emailLabel, color = Ink, fontSize = 14.sp, fontWeight = FontWeight.Medium)
                    Spacer(Modifier.height(6.dp))
                    OutlinedTextField(
                        value = email,
                        onValueChange = onEmailChange,
                        singleLine = true,
                        placeholder = { Text("name@example.com", color = Muted) },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Next),
                        modifier = Modifier.fillMaxWidth(),
                        shape = fieldShape,
                        textStyle = fieldText,
                        colors = fieldColors
                    )
                    Spacer(Modifier.height(16.dp))
                    Text(s.passwordLabel, color = Ink, fontSize = 14.sp, fontWeight = FontWeight.Medium)
                    Spacer(Modifier.height(6.dp))
                    OutlinedTextField(
                        value = password,
                        onValueChange = onPasswordChange,
                        singleLine = true,
                        visualTransformation = if (showPassword) VisualTransformation.None else PasswordVisualTransformation(),
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
                        keyboardActions = KeyboardActions(onDone = { if (canLogin) onLogin() }),
                        trailingIcon = {
                            IconButton(onClick = { showPassword = !showPassword }) {
                                Icon(if (showPassword) Icons.Filled.VisibilityOff else Icons.Filled.Visibility,
                                    contentDescription = null, tint = Muted, modifier = Modifier.size(20.dp))
                            }
                        },
                        modifier = Modifier.fillMaxWidth(),
                        shape = fieldShape,
                        textStyle = fieldText,
                        colors = fieldColors
                    )
                    Spacer(Modifier.height(14.dp))
                    PrivacyAccept(privacyAccepted, onPrivacyChange)
                    if (errorMessage != null) {
                        Spacer(Modifier.height(14.dp))
                        MessageBox(errorMessage)
                    }
                    Spacer(Modifier.height(20.dp))
                    PrimaryButton(s.loginButton, enabled = canLogin || isLoading, loading = isLoading, onClick = onLogin)
                }
            }
            Spacer(Modifier.height(14.dp))
            Text(s.loginHint, color = Muted, fontSize = 12.sp, textAlign = TextAlign.Center)
            Spacer(Modifier.weight(1f))
            Spacer(Modifier.height(24.dp))
            Text("ZyroTech BD", color = Muted, fontSize = 11.sp)
            Spacer(Modifier.height(24.dp))
        }
        Row(
            modifier = Modifier
                .align(Alignment.TopEnd)
                .statusBarsPadding()
                .padding(top = 8.dp, end = 12.dp)
                .clip(RoundedCornerShape(8.dp))
                .clickable { onToggleLang() }
                .padding(horizontal = 10.dp, vertical = 8.dp),
            verticalAlignment = Alignment.CenterVertically
        ) {
            Icon(Icons.Filled.Language, contentDescription = null, tint = Muted, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
            Text(s.langToggleLabel, color = Ink, fontSize = 13.sp, fontWeight = FontWeight.Medium)
        }
    }
}

/** The privacy policy (location recording for field work) must be accepted to sign in. */
@Composable
fun PrivacyAccept(checked: Boolean, onChange: (Boolean) -> Unit) {
    val s = LocalStrings.current
    val uri = androidx.compose.ui.platform.LocalUriHandler.current
    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.clickable { onChange(!checked) }) {
        androidx.compose.material3.Checkbox(checked = checked, onCheckedChange = onChange,
            colors = androidx.compose.material3.CheckboxDefaults.colors(checkedColor = Primary))
        Text(s.privacyCheck, color = Ink, fontSize = 14.sp, modifier = Modifier.weight(1f))
    }
    Text(s.privacySummary, color = Muted, fontSize = 12.sp, modifier = Modifier.padding(start = 12.dp))
    Text(s.privacyRead, color = Primary, fontSize = 13.sp, fontWeight = FontWeight.Medium,
        modifier = Modifier.padding(start = 12.dp, top = 4.dp).clickable { uri.openUri(PRIVACY_URL) })
}

val PRIVACY_URL: String = com.zyrotechbd.desk.BuildConfig.API_BASE_URL.substringBefore("/api/") + "/privacy"
