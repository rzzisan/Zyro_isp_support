# Meta App Review — WhatsApp permissions (Zyrotech BSOL app)

App: Zyrotech BSOL (ID 1900768904642203) · Business: Zareen Natural Foods (1082898213407533, verified)
Requesting advanced access: `whatsapp_business_messaging`, `whatsapp_business_management`
Demo number: +880 1933-635909 (WABA 3530709563744212, phone number ID 1305203072676835)
Demo app: https://support.zyrotechbd.com/admin/whatsapp

---

## 1. Usage descriptions (paste into App Review)

### whatsapp_business_messaging

Zyrotech builds customer-support software for small businesses in Bangladesh, starting with internet service providers (ISPs). Our app receives the WhatsApp messages that end customers send to the business's own WhatsApp number (via the Cloud API webhook), looks up that customer's account status in the business's billing system (bill due, connection status, router/ONU status), and sends a reply in Bangla back to the same customer — either drafted for a human agent or sent automatically when the business enables it. We also send utility template messages the business has approved, such as bill-due reminders. Messages are only sent to customers who contacted the business or who are existing subscribers of that business, and replies are sent within the 24-hour customer service window unless an approved template is used. We need advanced access so that businesses who onboard through our Embedded Signup (including WhatsApp Business app Coexistence) can receive and send these support messages through our app.

### whatsapp_business_management

Our app uses whatsapp_business_management to onboard a business's WhatsApp Business Account through Embedded Signup, read its phone numbers and their status (display number, quality rating, platform type, Coexistence status), subscribe our app to the account's webhooks (subscribed_apps), synchronise contacts and chat history for WhatsApp Business app (Coexistence) users within the 24-hour onboarding window, and create and list message templates (e.g. bill reminders) from the business's admin dashboard. We only access WhatsApp Business Accounts that the business owner explicitly connects to our app.

---

## 2. Screen recordings (record with the demo app, English UI labels are fine either way)

Video A — whatsapp_business_messaging (about 1 minute)
1. Show https://support.zyrotechbd.com/admin/whatsapp, logged in.
2. In "মেসেজ পাঠান": enter your own phone number, template `hello_world`, language `en_US`, press পাঠান.
3. Show the success message with the message id.
4. Switch to the phone (or WhatsApp Web) and show the message arriving from +880 1933-635909.
5. Optional but strong: reply from the phone, then show the reply arriving in the dashboard (ড্যাশবোর্ড → সাম্প্রতিক খসড়া).

Video B — whatsapp_business_management (about 1 minute)
1. Same page, "Message template তৈরি": name `bill_reminder_demo`, language `en_US`, category UTILITY, body e.g. "Hello, your internet bill is due tomorrow. Please pay to avoid service interruption."
2. Press Template জমা দিন, show the success message with template id/status.
3. Refresh: the template appears in the list on the page.
4. Open WhatsApp Manager → Message templates and show the same template there.

---

## 3. Before submitting (checklist)

- [ ] System user token saved in the dashboard (WhatsApp page → "Cloud API-তে থাকা নম্বর ম্যানুয়ালি সেট করুন").
- [ ] Privacy policy (https://bsol.zyrotechbd.com/privacy) has a WhatsApp section — suggested text below.
- [ ] App icon + category set in App settings → Basic (already passed review before, re-check).
- [ ] Both videos uploaded, descriptions pasted, then Submit.

### Suggested privacy-policy section (add as 1.6 in BSOL admin → Privacy page)

**1.6 WhatsApp Business Platform (Meta)**
If a business connects its WhatsApp Business number to our services, we use the WhatsApp Business Platform (Cloud API) with the business's explicit permission to receive the messages its customers send to that number, and to send replies and approved template messages on the business's behalf. We process the customer's WhatsApp phone number, profile name and message content, and may look up that customer's account information in the business's own systems (for example billing or connection status) only to answer the customer's request. For businesses that also use the WhatsApp Business app, we may synchronise their contacts and recent chat history when they connect, as allowed by Meta. This data is used only to provide customer support for that business, is not sold, and is not used for advertising. Businesses can disconnect at any time from WhatsApp Business app settings or by contacting us.
