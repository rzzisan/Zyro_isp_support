package com.zyrotechbd.desk

import androidx.compose.foundation.layout.Column
import androidx.compose.material3.Text
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.setValue
import androidx.compose.ui.test.junit4.createComposeRule
import androidx.compose.ui.test.onNodeWithText
import com.zyrotechbd.desk.ui.components.ScreenBody
import org.junit.Rule
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34])
class EarlyReturnTest {
    @get:Rule val rule = createComposeRule()

    @Test
    fun earlyReturnInsideScreenBody() {
        var data by mutableStateOf<String?>(null)
        rule.setContent {
            Column {
                ScreenBody {
                    Text("head")
                    val d = data ?: run {
                        Text("loading")
                        return@ScreenBody
                    }
                    Text("a $d")
                    Text("b $d")
                }
            }
        }
        rule.onNodeWithText("loading").assertExists()
        data = "x"
        rule.waitForIdle()
        rule.onNodeWithText("a x").assertExists()
        data = null
        rule.waitForIdle()
        rule.onNodeWithText("loading").assertExists()
    }
}
