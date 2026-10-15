# Mail settings

**Settings > Mail** sets how CMS sends email, so you don't have to edit `.env` on the server. It needs the **Manage settings** permission.

| Setting | What it does |
|---|---|
| **Send with** | **SMTP** (a mail server or a service such as Postmark, Mailgun or Amazon SES), **Sendmail** (the server's own mail program) or **Log only** (emails are written to `storage/logs/laravel.log` and not sent, for testing). |
| **Server, port, security** | For SMTP: the host name, the port (usually 587 with STARTTLS, or 465 with SSL/TLS) and which of the two it uses. |
| **Username, password** | For SMTP. The password is stored encrypted and never shown again; leave it blank to keep the saved one, or tick **Remove the saved password**. |
| **From address and name** | Who emails come from. |

Saved settings win over the `MAIL_*` values in `.env`. The queue worker is told to restart after a change so it picks them up.

**Send a test email** sends a message straight away (not through the queue) to the address you give, and shows the mail server's error if it fails.

In demo mode the mail settings can't be changed and nothing is sent.
