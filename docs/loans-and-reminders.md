# Loans & push reminders

[← README](../README.md)

## Three kinds of books

When adding a book you choose its type:

| Type | Meaning | Location | Due date |
|---|---|---|---|
| **Own book** | bought or a present | a shelf compartment | – |
| **Library loan** | from a public/university library | none | required |
| **Borrowed from a person** | from friends or family | none | optional |

Converting a loan into an own book later (“I bought it”) clears the loan fields.

## Library loans with a default loan period

Under *Settings → My library* everyone can store the name of their library and its **loan period**
(e.g. 21 days). The introduction wizard asks for it as well. Then:

- new library loans have the library already filled in,
- you only enter the **borrowing date** – the due date is calculated (borrowing date + loan period)
  and follows when you change the borrowing date, until you set it by hand
  (“Use default period” brings it back),
- **Extend** proposes the current due date + one loan period.

Without your own setting, the form suggests 28 days.

## Keeping track

- The **home page** lists *Upcoming returns* sorted by due date – overdue and ≤ 3 days are highlighted.
- The book list has a *Loans* filter and can sort by due date.
- *Mark as returned* keeps the book in the history (and your reading data) but removes it from the lists.

## Lending your own books

On the page of an own book: *Lend* → to whom. The book then appears under *Lent out* on the home page
and in the *Lent out* filter until you tap *Got it back*.

## Push notifications

The person who borrowed the book gets a push notification **3 days and 1 day before** the due date and
**on the due date**. Requirements:

1. **The [cron job](installation.md#7-cron-job) runs** – it sends the reminders. Each reminder is sent
   only once, no matter how often the cron URL is called.
2. **Push is enabled on the device**: *Settings → Push notifications → Enable*, then *Send test*.
   The app also offers it at fitting moments (e.g. right after adding a loan) and again later until
   it is switched on.

Nothing to configure on the server – the VAPID keys are generated automatically
([configuration](configuration.md#push-notifications)).

### iPhone and iPad

iOS (16.4 and newer) only delivers web push to **installed** apps: first *Share → Add to Home Screen*,
open BookSpot from the home screen, then enable push there. The app shows a step-by-step guide.

### Push does not arrive?

- Was the test notification delivered? If not: check the browser's/OS notification permission for the site.
- Is the cron job running? Admins see the URL under *Settings → Cron job*; call it once in the browser
  – the JSON answer lists how many reminders were checked and sent.
- While an admin is signed in **as someone else**, push cannot be enabled (the device would get that
  person's reminders).
