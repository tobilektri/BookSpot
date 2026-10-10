# Users & roles

[← README](../README.md)

One BookSpot installation is one household: everybody shares the **catalogue, shelves and loans**,
while **reading status, rating, progress, dates and notes are private** to each person. On a book's
page you see who else has read it (status and rating only – never their notes).

## Roles

| | Admin | User |
|---|:---:|:---:|
| Scan, add, edit, move books; edit shelves; import/export | ✅ | ✅ |
| Own reading data, statistics, push | ✅ | ✅ |
| Create, invite, change and delete accounts | ✅ | – |
| “Sign in as” another user | ✅ | – |
| See the cron URL and system information | ✅ | – |

The **first account** created on a fresh installation is always an admin. There must always be at
least one admin; you cannot delete yourself.

## Adding people

*Settings → User management* (admins):

- **With invitation** (recommended): enter username and e-mail, tick *Send invitation*. The person
  gets an e-mail with a link (valid `INVITE_VALID_DAYS`, default 7) and chooses their own password.
  If the server cannot send mail, the app shows the link so you can pass it on yourself. Invitations
  can be re-sent from the list.
- **With password**: set an initial password and tell it to the person.
- Choose the role *Admin* or *User*; it can be changed later, as can e-mail and password.

Invitation e-mails are sent with PHP `mail()`. Set `MAIL_FROM` to an address of your own domain –
many providers reject mail from unknown senders ([configuration](configuration.md#accounts-and-sessions)).

Other ways to create accounts:

- `ALLOW_REGISTRATION=true` shows a sign-up form on the login page (new accounts are *users*).
- On the command line: `php backend/bin/create-user.php <username> <email> <password> [de|en]`.

## “Sign in as” (impersonation)

To help someone (“where did my book go?”), an admin can open the app **as that person** from the
user list. A purple bar shows whose view you are in, with *Back to …* to return. While impersonating,
the person's introduction is not marked as completed and push cannot be enabled on your device.

## Introduction wizard

Everyone gets a short introduction on their first sign-in – what the app can do, scanning, reading
status, shelves, the own library and loan period, push and installing the app. Admins get extra
steps about import, the shelf editor, user management and the cron job. It can be started again any
time under *Settings → Show introduction again*.

<img src="images/mobile-wizard-en.png" width="280" alt="Introduction wizard">

## Profile

*Settings → Profile*: e-mail, language (German/English – the app name follows: SchmökerSpot/BookSpot)
and password. *Settings → My library*: library name and default loan period
([loans](loans-and-reminders.md)).

## Security notes

- Passwords are hashed with Argon2id (bcrypt where the host's PHP lacks Argon2).
- Failed logins are throttled per IP; after too many attempts you have to wait 15 minutes.
- Sessions use `HttpOnly`, `SameSite=Strict` and – via HTTPS – `Secure` cookies.
- Invitation tokens are random, stored only as a hash and can be used once.
