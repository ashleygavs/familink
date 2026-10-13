# FamiLink walkthrough: role by role

Tick each box as you go. Write down anything that does not match the "Expected" result, with the page and what you did.

All test accounts use the password `password`. Open the app at **http://localhost:8000**.

| Account | Role | Works in |
|---|---|---|
| `superadmin@familink.test` | Super admin | Everything |
| `admin@familink.test` | Admin (Bacolor) | San Jose and Cabalantian |
| `admin2@familink.test` | Admin (Lubao) | Santa Cruz and San Nicolas |
| `stakeholder@familink.test` | Stakeholder | Read-only reports, everywhere |
| `encoder@familink.test` | Encoder | San Jose only |
| `encoder3@familink.test` | Encoder | Cabalantian only |
| `encoder2@familink.test` | Encoder | Santa Cruz only |

Tip: use a different browser (or a private window) for each role, so you stay logged in to several at once.

## Test data you will use

| Household | Barangay | Members (resident ID) |
|---|---|---|
| HH-SJ-0001 | San Jose | Juan `FL-2026-SJ-00001`, Maria `-00002`, Pedro `-00003` |
| HH-CAB-0001 | Cabalantian | Carmen `FL-2026-CAB-00001`, Roberto `-00002`, Elena `-00003`, Miguel, Sofia (5 people, three generations) |
| HH-CAB-0002 | Cabalantian | Teresa `FL-2026-CAB-00006`, Lito, Joel |
| HH-SC-0001 | Santa Cruz | Antonio `FL-2026-SC-00001` and family (5 people) |
| HH-SC-0002 | Santa Cruz | Cristina `FL-2026-SC-00006`, Ian, Jade |
| HH-SN-0001 | San Nicolas | Ramon `FL-2026-SN-00001`, Linda, Carlo |

Relief events

| Event | Municipality | Type | Status |
|---|---|---|---|
| Rice Distribution (per household) | Bacolor | One claim per household | Active |
| Cash Aid (per resident, max 3 per household) | Bacolor | One claim per person, 3 per household | Active |
| Typhoon Aid (still a draft) | Bacolor | One claim per household | Draft |
| Old Relief (closed) | Bacolor | One claim per household | Closed |
| Lubao Relief Packs | Lubao | One claim per household | Active |

There are no residents waiting for approval right now. Part A creates some.

---

## 0. Before you start

- [ ] The database is backed up (see "Back up and restore" at the end).
- [ ] Your code changes are committed.
- [ ] The "FamiLink dev" terminal tab is running and http://localhost:8000/login opens.

---

## A. Encoder

### A1. Encoder, San Jose (`encoder@familink.test`)

**Login and menu**
- [ ] After login you land on **Register Resident**. Expected: the header shows "Barangay San Jose".
- [ ] The menu shows: Dashboard, Register Resident, Residents, Scan QR Code. Nothing else.
- [ ] The Dashboard counts only San Jose.

**Register a new family (creates someone to approve later)**
- [ ] Register a new person who is not in the system, starting a new family record. Fill in the steps in order.
- [ ] The Review step shows the person, household and family you entered.
- [ ] Submit. Expected: a success message and a QR card with a Print button.
- [ ] Reload the page halfway through the steps: your answers are still there.

**Join an existing family**
- [ ] Register another person, choosing to join an existing family. Search for "Dela Cruz".
- [ ] Suggested relatives appear with Yes, No and Not sure. Say No to one: the next best suggestion replaces it.
- [ ] Say Yes to a father or mother, and finish the registration.

**Duplicate and rule checks**
- [ ] Try to register the same name and birthdate again. Expected: a clear "already registered" message.
- [ ] Try a father who is younger than the child, or a mother born after the child. Expected: refused with a reason.

**Residents page and QR cards**
- [ ] Open **Residents**. Search "Juan", then "FL-2026-SJ-00002", then "HH-SJ-0001": each finds the right person.
- [ ] Only San Jose people appear. Search "Villanueva": no results.
- [ ] Click **QR card** for Juan: a clean page with no menu. Click **Print** (print preview shows only the cards).
- [ ] Click **Whole household** for Juan: three cards (Juan, Maria, Pedro).
- [ ] Switch **Show** to "Waiting for approval": your new registrations appear, with no QR card button.

**Scan QR Code (type the ID, no camera needed)**
- [ ] The event list shows only the two Bacolor Active events. No Lubao event, no Draft, no Closed.
- [ ] Choose "Rice Distribution", type `FL-2026-SJ-00001`, press Check. Expected: green APPROVED, a beep, and it resets after 4 seconds.
- [ ] Type `FL-2026-SJ-00002` (Maria, same household). Expected: red NOT ALLOWED, "This household already claimed relief", with who and when.
- [ ] Type `FL-2026-SJ-00001` again. Expected: red, already claimed.
- [ ] Switch to "Cash Aid", type Maria's ID, then Pedro's. Expected: both green (per person).
- [ ] Type `not-a-real-id`. Expected: red, "This code is not registered".
- [ ] Type the ID of one of your new, waiting-for-approval residents. Expected: red, "still waiting for approval".

**Forbidden for this role** (each should show a 403 page or "Unauthorized")
- [ ] http://localhost:8000/admin/approvals
- [ ] http://localhost:8000/admin/events
- [ ] http://localhost:8000/admin/users
- [ ] http://localhost:8000/admin/municipalities
- [ ] http://localhost:8000/reports/analytics
- [ ] A Cabalantian card: http://localhost:8000/residents/25/card (Roberto)
- [ ] A Lubao household sheet: http://localhost:8000/households/9/cards

### A2. Encoder, Cabalantian (`encoder3@familink.test`)
- [ ] Header shows "Barangay Cabalantian". **Residents** lists only Cabalantian people (Villanueva, Ramos), never Dela Cruz.
- [ ] **Scan QR Code**, "Cash Aid": scan Carmen, Roberto, Elena (`FL-2026-CAB-00001` to `-00003`). Expected: all green.
- [ ] Scan Miguel (`FL-2026-CAB-00004`), the 4th person in that household. Expected: red, "already used all 3 claims allowed". Sofia is `FL-2026-CAB-00005`.
- [ ] Switch to "Rice Distribution", scan Teresa (`FL-2026-CAB-00006`). Expected: green. Scan Lito (same household). Expected: red, household claimed.
- [ ] Scan a San Jose ID (`FL-2026-SJ-00003`). Expected: red, "belongs to another barangay".

### A3. Encoder, Lubao (`encoder2@familink.test`)
- [ ] The scanner lists only "Lubao Relief Packs" and it is selected automatically.
- [ ] Scan Antonio (`FL-2026-SC-00001`). Expected: green. Scan another Manalo. Expected: red, household claimed.
- [ ] Scan a Bacolor ID. Expected: red, another municipality.

---

## B. Admin

### B1. Admin, Bacolor (`admin@familink.test`)

**Login and menu**
- [ ] After login you land on **Approvals**. The header shows "Bacolor: Cabalantian, San Jose".
- [ ] The menu shows: Dashboard, Residents, Approvals, Relief Events, Suggestion Report, Analytics, Users.

**Approvals**
- [ ] The people you registered in A1 are listed, each with details and **View family tree**.
- [ ] Suggested links show for a person with an empty father, mother or spouse slot. Confirm one with **Yes, confirm link**.
- [ ] Click **Unlink** next to a link and confirm. Expected: the link is gone and the person is not deleted.
- [ ] **Approve** one person. Expected: they leave the list and can now have a QR card.
- [ ] **Archive** another and confirm. Expected: they leave the list.
- [ ] Open that approved person under **Residents**: the QR card button now exists.

**Changes after approval (marriage and household moves)**
- [ ] Open **Residents** and click **Update** next to Miguel (Cabalantian). The page shows his family, household and an empty history.
- [ ] **Record a marriage**: search "Ramos", choose Teresa, and let her take his surname. Expected: both Married and linked, Teresa shown as "Teresa Villanueva", and a history line on each.
- [ ] Try to marry him to his own sister (Sofia): she is not offered in the search. A child under 16 is not offered either.
- [ ] While a relief event is open (the two Bacolor events are Active), the **Move to another household** button is greyed out and an amber note names the open events. Expected: no move is possible.
- [ ] Close both Bacolor events (and the one you created), then **Move to another household**: choose "A household already on record", pick `HH-CAB-0002`, relationship Spouse. Expected: he now lives in `HH-CAB-0002`.
- [ ] Check the earlier relief claims: they still belong to `HH-CAB-0001`.
- [ ] Recording a marriage is still allowed while an event is open (only household moves are paused).
- [ ] Move the head of a household (Roberto): you must choose who becomes the new head.
- [ ] As an encoder, the **Update** button is not shown, and `/admin/residents/27` is forbidden.

**Relief events**
- [ ] Open **Relief Events**: the four Bacolor events are shown, and no Lubao event.
- [ ] **New event**: create "Test Aid", Per Resident, start today, end next week, status Draft. Expected: it appears as Draft. You do not see a "Most members per household" box (only a super admin does).
- [ ] **Activate** it. Expected: Active, "Open for scanning".
- [ ] **Edit** the Active event: only name, end date and status can change. The type and start date are locked.
- [ ] **Close** it and confirm. Expected: Closed, "Finished", and no Edit button.
- [ ] Create another Draft and **Delete** it. Expected: gone.
- [ ] Try to delete an Active event: the Delete button is not offered.
- [ ] Open an encoder's scanner: the new Active event appears. The Closed one does not.

**Suggestion report**
- [ ] Open **Suggestion Report**: your Yes / No / Not sure answers from A1 are counted.
- [ ] Change **Time period** and **Place**: the numbers change. A Lubao place is not offered.

**Analytics**
- [ ] Open **Analytics**: only San Jose and Cabalantian rows. Pick an event: the coverage changes.
- [ ] Search a household code in **Households**, then open **Family tree**: a tree with parents above and children below, spouses side by side.
- [ ] Open HH-CAB-0001's tree: three generations (Carmen, then Roberto and Elena, then the children).

**Staff accounts**
- [ ] Open **Users**: only encoders of your barangays.
- [ ] **Add account**: an encoder for Cabalantian. Role choices offered: Encoder only.
- [ ] Try to give that new account a barangay in Lubao. Expected: not offered or refused.
- [ ] **Deactivate** an encoder, confirm. Expected: "Inactive" label. In the encoder's browser, the next click logs them out, and logging in again says the account is deactivated.
- [ ] **Reactivate** them. Expected: they can log in again.
- [ ] You cannot see or edit admins or super admins here.

**Forbidden for this role**
- [ ] http://localhost:8000/admin/municipalities
- [ ] http://localhost:8000/encoder/register and http://localhost:8000/encoder/scanner
- [ ] A Lubao household: http://localhost:8000/family-tree/9 and http://localhost:8000/households/9/cards

### B2. Admin, Lubao (`admin2@familink.test`)
- [ ] Header shows "Lubao: San Nicolas, Santa Cruz".
- [ ] **Approvals**, **Residents**, **Analytics** show only Lubao people. No Villanueva, no Dela Cruz.
- [ ] **Relief Events** shows only "Lubao Relief Packs".
- [ ] Open http://localhost:8000/family-tree/1 (San Jose). Expected: forbidden.
- [ ] Create a new encoder for San Nicolas. Expected: allowed (this barangay had no encoder).

---

## C. Stakeholder (`stakeholder@familink.test`)
- [ ] After login you land on **Analytics**. The menu shows Dashboard and Analytics only.
- [ ] All municipalities and barangays appear. The numbers cover everyone.
- [ ] Pick each event: the coverage figures reflect the claims from Part A.
- [ ] A household's **Family tree** opens from any barangay.
- [ ] Nothing here lets you change data.

**Forbidden for this role**
- [ ] http://localhost:8000/admin/approvals, `/admin/events`, `/admin/users`, `/admin/suggestion-report`
- [ ] http://localhost:8000/encoder/register, `/encoder/scanner`, `/residents`

---

## D. Super admin (`superadmin@familink.test`)

**Menu and scope**
- [ ] After login you land on **Users**. The header shows "All Barangays".
- [ ] The menu shows every item, including **Municipalities**.

**Municipalities**
- [ ] Add a municipality "Test Town, Test Province". Add a barangay "Test Barangay" with code `TB` under it.
- [ ] Try the same barangay code again. Expected: refused.
- [ ] Delete "Test Barangay", then the municipality. Expected: both deleted (they have nothing in them).
- [ ] Try to delete **Cabalantian** or **Bacolor**. Expected: refused, they still have residents or barangays.

**Users**
- [ ] **Add account**: an admin. Choose the Lubao municipality and tick both barangays. Expected: created.
- [ ] Try an admin whose barangays are from another municipality than the one chosen. Expected: refused.
- [ ] Add an encoder with exactly one barangay, and a stakeholder.
- [ ] **Edit** your own account: the role cannot change.
- [ ] **Deactivate** yourself: not offered. Deactivate another super admin if there is one.
- [ ] **Delete** an account you just created with no history: deleted.
- [ ] Try to delete the admin who approved residents (`admin@familink.test`): the Delete button is hidden, and **Deactivate** is the option.

**Relief events**
- [ ] **New event**, Per Resident: a **Most members per household** box appears. Set it to 2.
- [ ] Choose Per Household: the box disappears.
- [ ] As an encoder, scan three members of one household for a Per Resident event with limit 2: the third is refused.
- [ ] Edit an **Active** event's limit: allowed for the super admin.
- [ ] You can create events for either municipality.

**Reports**
- [ ] **Analytics** and **Suggestion Report** cover every barangay. Filter by municipality and barangay.

---

## E. Across all roles
- [ ] The sidebar collapses to icons and expands again with nothing cut off.
- [ ] **Log Out** works, and the back button does not return to a private page.
- [ ] A wrong password shows a clear error. Five wrong attempts in a minute are slowed down.
- [ ] Two encoders scanning the same household in two browsers at once: only one claim succeeds.
- [ ] The register stepper works on a phone-width window (about 400 pixels).

## F. Camera scanning (when you are ready)
- [ ] Follow "Phone setup" below, open **Scan QR Code**, and scan a card shown on the PC screen (http://localhost:8000/households/1/cards).
- [ ] Expected: green APPROVED for the first member, red for the second member of the same household.

---

## How to start the system (from nothing)

1. **Start MySQL.** Open XAMPP Control Panel (`C:\xampp\xampp-control.exe`) and click **Start** next to **MySQL**. You do not need Apache.
2. **Open PowerShell** in the project folder:
   ```powershell
   cd C:\Users\MateBook\FamiLink
   ```
3. **Start the app** (one terminal, leave it open):
   ```powershell
   php C:\Users\MateBook\.config\herd-lite\bin\composer.phar run dev
   ```
   If `composer` works in your terminal you can use `composer run dev`.
4. Open **http://localhost:8000**.
5. To stop: press `Ctrl+C` in that terminal.

**If something goes wrong**
- *"Address already in use" or pages hang:* two copies are running. Close all terminals, then run `Get-Process php, node` and stop any left over with `Stop-Process -Id <number>`. Start once.
- *"Unable to locate file in Vite manifest":* run `npm run build`, or start with the command in step 3.
- *Database errors:* check MySQL is green in XAMPP, then run `php artisan migrate`.
- *Everything looks old after a change:* press `Ctrl+Shift+R` in the browser.

## Back up and restore the database

Back up (creates `familink-backup.sql` in the project folder):
```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root familink > familink-backup.sql
```

Restore (replaces the current data with the backup):
```powershell
Get-Content familink-backup.sql | C:\xampp\mysql\bin\mysql.exe -u root familink
```

Put the test scenario back (adds anything missing, never duplicates):
```powershell
php artisan db:seed --class=WalkthroughSeeder
```
This does not reset residents you approved or events you closed. To get the original state again, restore the backup.

## Phone setup for camera scanning (Android, USB, works offline)

A phone camera only works on `https://` pages or on `localhost`. A USB cable makes the phone treat your PC as its own `localhost`. No Wi-Fi or internet is needed.

**Once, on the phone**
1. **Settings → About phone**, tap **Build number** 7 times.
2. **Settings → Developer options → USB debugging**: turn on.
3. Plug the phone into the PC. When it asks, choose **File transfer** (pull down the notification shade, tap the USB notification, pick **File transfer**) and tap **Allow USB debugging** (tick "Always allow").

**Prepare the app to serve built files** (the live development server cannot be reached from the phone)
```powershell
# 1. Press Ctrl+C in the "composer run dev" terminal
# 2. Remove the development marker and build:
Remove-Item public\hot -ErrorAction SilentlyContinue
npm run build
# 3. Start only the web server:
php artisan serve
```
Leave the terminal open.

**Connect**
1. On the PC, open **Chrome** at `chrome://inspect/#devices`.
2. Tick **Discover USB devices**. Your phone appears.
3. Click **Port forwarding…**, add port `8000` with address `localhost:8000`, tick **Enable port forwarding**, and click **Done**. A green dot should appear next to port 8000.
4. On the phone's Chrome, open **http://localhost:8000**, log in as an encoder, open **Scan QR Code**, and tap **Allow** when it asks for the camera.

**Go back to normal development**
```powershell
# Press Ctrl+C in the "php artisan serve" terminal, then:
php C:\Users\MateBook\.config\herd-lite\bin\composer.phar run dev
```
While in the "built files" mode, code changes do not appear until you run `npm run build` again.

**Troubleshooting the phone**
- *Phone not listed in `chrome://inspect`:* try another cable (many only charge), unlock the phone, and accept the "Allow USB debugging" prompt.
- *No camera prompt:* make sure the address is exactly `http://localhost:8000`, not the `192.168...` address, and the green port-forwarding dot is on.
- *iPhone:* USB forwarding does not work. You need an https tunnel (for example `cloudflared`), which needs internet.
