package com.zyrotechbd.desk.ui.screens

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
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
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material.icons.filled.VisibilityOff
import androidx.compose.material3.CircularProgressIndicator
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
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Brush
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
import com.zyrotechbd.desk.ui.strings.LocalStrings

private val darkBg = Brush.verticalGradient(colors = listOf(Color(0xFF08101E), Color(0xFF0D2033), Color(0xFF0A2A35)))
private val logoBg = Brush.linearGradient(colors = listOf(Color(0xFF0B5F5E), Color(0xFF19A59C)))
private val btnGradient = Brush.horizontalGradient(colors = listOf(Color(0xFF0F7C7B), Color(0xFF19A59C)))
private val accent = Color(0xFF19A59C)

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
) {
    val s = LocalStrings.current
    var showPassword by remember { mutableStateOf(false) }
    val canLogin = !isLoading && email.isNotBlank() && password.isNotBlank()
    val label = Color(0xFF8BA9C0)
    val fieldColors = OutlinedTextFieldDefaults.colors(
        focusedBorderColor = accent,
        unfocusedBorderColor = Color.White.copy(alpha = 0.18f),
        focusedContainerColor = Color.White.copy(alpha = 0.10f),
        unfocusedContainerColor = Color.White.copy(alpha = 0.05f),
        cursorColor = accent
    )

    Box(modifier = Modifier.fillMaxSize().background(darkBg)) {
        Canvas(modifier = Modifier.fillMaxSize()) {
            drawCircle(color = accent.copy(alpha = 0.08f), radius = 320.dp.toPx(), center = Offset(size.width * 0.9f, size.height * 0.08f))
            drawCircle(color = Color(0xFF1598F5).copy(alpha = 0.06f), radius = 200.dp.toPx(), center = Offset(-40.dp.toPx(), size.height * 0.45f))
        }
        Column(
            modifier = Modifier.fillMaxSize().imePadding().verticalScroll(rememberScrollState()).padding(horizontal = 28.dp),
            horizontalAlignment = Alignment.CenterHorizontally
        ) {
            Spacer(Modifier.height(80.dp))
            Box(
                modifier = Modifier.size(82.dp).clip(RoundedCornerShape(22.dp)).background(logoBg),
                contentAlignment = Alignment.Center
            ) {
                Text("ZD", color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 26.sp, letterSpacing = 1.sp)
            }
            Spacer(Modifier.height(18.dp))
            Text("Zyro Desk", color = Color.White, fontWeight = FontWeight.ExtraBold, fontSize = 22.sp)
            Spacer(Modifier.height(4.dp))
            Text(s.loginSubtitle, color = label, fontSize = 13.sp, textAlign = TextAlign.Center)
            Spacer(Modifier.height(40.dp))

            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .clip(RoundedCornerShape(24.dp))
                    .background(Color.White.copy(alpha = 0.05f))
                    .border(1.dp, Color.White.copy(alpha = 0.12f), RoundedCornerShape(24.dp))
                    .padding(24.dp)
            ) {
                Column {
                    Text(s.emailLabel, color = label, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                    Spacer(Modifier.height(6.dp))
                    OutlinedTextField(
                        value = email,
                        onValueChange = onEmailChange,
                        singleLine = true,
                        placeholder = { Text("name@example.com", color = Color(0xFF3A5060)) },
                        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Email, imeAction = ImeAction.Next),
                        modifier = Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(14.dp),
                        textStyle = TextStyle(color = Color.White, fontSize = 16.sp),
                        colors = fieldColors
                    )
                    Spacer(Modifier.height(16.dp))
                    Text(s.passwordLabel, color = label, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
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
                                    contentDescription = null, tint = Color(0xFF5A7A8A), modifier = Modifier.size(20.dp))
                            }
                        },
                        modifier = Modifier.fillMaxWidth(),
                        shape = RoundedCornerShape(14.dp),
                        textStyle = TextStyle(color = Color.White, fontSize = 16.sp),
                        colors = fieldColors
                    )
                    Spacer(Modifier.height(22.dp))
                    Box(
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(52.dp)
                            .clip(RoundedCornerShape(16.dp))
                            .background(if (canLogin) btnGradient else Brush.horizontalGradient(listOf(Color(0xFF1E2E3A), Color(0xFF1E2E3A))))
                            .clickable(enabled = canLogin) { onLogin() },
                        contentAlignment = Alignment.Center
                    ) {
                        if (isLoading) {
                            CircularProgressIndicator(color = Color.White, modifier = Modifier.size(24.dp), strokeWidth = 2.5.dp)
                        } else {
                            Text(s.loginButton, color = if (canLogin) Color.White else Color(0xFF3A5060),
                                fontWeight = FontWeight.Bold, fontSize = 15.sp)
                        }
                    }
                    if (errorMessage != null) {
                        Spacer(Modifier.height(14.dp))
                        Box(
                            modifier = Modifier
                                .fillMaxWidth()
                                .clip(RoundedCornerShape(12.dp))
                                .background(Color(0xFFB24734).copy(alpha = 0.14f))
                                .border(1.dp, Color(0xFFB24734).copy(alpha = 0.3f), RoundedCornerShape(12.dp))
                                .padding(12.dp)
                        ) {
                            Text(errorMessage, color = Color(0xFFEF9A9A), fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
                        }
                    }
                }
            }
            Spacer(Modifier.height(16.dp))
            Text(s.loginHint, color = label.copy(alpha = 0.7f), fontSize = 11.sp, textAlign = TextAlign.Center)
            Spacer(Modifier.weight(1f))
            Text("Powered by ZyroTech BD", color = Color(0xFF2E4A5A), fontSize = 11.sp)
            Spacer(Modifier.height(32.dp))
        }
        Row(
            modifier = Modifier
                .align(Alignment.TopEnd)
                .padding(top = 44.dp, end = 16.dp)
                .clip(RoundedCornerShape(20.dp))
                .background(Color.White.copy(0.08f))
                .border(1.dp, Color.White.copy(0.18f), RoundedCornerShape(20.dp))
                .clickable { onToggleLang() }
                .padding(horizontal = 14.dp, vertical = 7.dp),
            horizontalArrangement = Arrangement.Center
        ) {
            Text(s.langToggleLabel, color = label, fontSize = 12.sp, fontWeight = FontWeight.SemiBold)
        }
    }
}
