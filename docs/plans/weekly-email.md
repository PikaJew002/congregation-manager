# Weekly email

Congregation-scoped list, Markdown template, and a weekly send window.

- Recipients are added by hand. Unsubscribe tokens opt a person out; an admin can subscribe them again.
- The scheduler creates one run per congregation per send start. Each recipient gets one delayed job spread across the window.
- Mail goes out through the MailChannels transport, one recipient per message, with list-unsubscribe headers.
