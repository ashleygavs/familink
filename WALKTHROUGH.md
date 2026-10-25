# FamiLink walkthrough: role by role

Tick each box as you go. Write down anything that does not match the "Expected" result, with the page and what you did.

All test accounts use the password `password` (unless you changed one). Open the app at **http://localhost:8000**.

Every barangay has **one admin and one encoder**, and every admin handles **one barangay**. Account names follow `<municipality code>_<barangay> <role>`, for example `BAC_San Jose Admin` (BAC Bacolor, LUB Lubao, MEX Mexico, SLU San Luis).

| Municipality | Barangay | Admin | Encoder |
|---|---|---|---|
| Bacolor | San Jose | `bac.sanjose.admin@familink.test` | `bac.sanjose.encoder@familink.test` |
| Bacolor | Cabalantian | `bac.cabalantian.admin@familink.test` | `bac.cabalantian.encoder@familink.test` |
| Lubao | Santa Cruz | `lub.santacruz.admin@familink.test` | `lub.santacruz.encoder@familink.test` |
| Lubao | San Nicolas | `lub.sannicolas.admin@familink.test` | `lub.sannicolas.encoder@familink.test` |
| Mexico | San Jose Malino | `mex.sanjosemalino.admin@familink.test` | `mex.sanjosemalino.encoder@familink.test` |
| San Luis | Sta. Monica | `slu.stamonica.admin@familink.test` | `slu.stamonica.encoder@familink.test` |

| Account | Role | Works in |
|---|---|---|
| `superadmin@familink.test` | Super admin | Everything |
| `stakeholder@familink.test` | Stakeholder | Read-only reports, everywhere |

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

Mexico (San Jose Malino) and San Luis (Sta. Monica) have no residents, households or relief events yet, so they start empty. There are no residents waiting for approval right now. Part A creates some.

---

## 0. Before you start

- [ ] The database is backed up (see "Back up and restore" at the end).
- [ ] Your code changes are committed.
- [ ] The "FamiLink dev" terminal tab is running and http://localhost:8000/login opens.

---

## A. Encoder

### A1. Encoder, San Jose (`bac.sanjose.encoder@familink.test`)

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
- [ ] In **Register Resident**, search for a parent who lives in another barangay (for example a Cabalantian person from San Jose). The result shows the name, gender, birth year and barangay only: no resident ID, exact birthdate or address. People of your own barangay still show everything.
- [ ] In **Register Resident**, try to choose a **Spouse** who lives in another barangay. The search only offers people of your own barangay. If a request is forced through anyway, it is refused: "You can only link a husband or wife who lives in a barangay you handle." Choosing someone who is already married to a living person, or who is archived or deceased, is refused with a plain reason. A widow or widower can be linked, and both people then show as Married.

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

### A2. Encoder, Cabalantian (`bac.cabalantian.encoder@familink.test`)
- [ ] Header shows "Barangay Cabalantian". **Residents** lists only Cabalantian people (Villanueva, Ramos), never Dela Cruz.
- [ ] **Scan QR Code**, "Cash Aid": scan Carmen, Roberto, Elena (`FL-2026-CAB-00001` to `-00003`). Expected: all green.
- [ ] Scan Miguel (`FL-2026-CAB-00004`), the 4th person in that household. Expected: red, "already used all 3 claims allowed". Sofia is `FL-2026-CAB-00005`.
- [ ] Switch to "Rice Distribution", scan Teresa (`FL-2026-CAB-00006`). Expected: green. Scan Lito (same household). Expected: red, household claimed.
- [ ] Scan a San Jose ID (`FL-2026-SJ-00003`). Expected: red, "belongs to a resident of another barangay or municipality". No name, ID or barangay of that person is shown.

### A3. Encoder, Santa Cruz (`lub.santacruz.encoder@familink.test`)
- [ ] Header shows "Barangay Santa Cruz". The scanner lists only "Lubao Relief Packs" and it is selected automatically.
- [ ] Scan Antonio (`FL-2026-SC-00001`). Expected: green. Scan another Manalo. Expected: red, household claimed.
- [ ] Scan a Bacolor ID. Expected: red, another municipality. Scan a San Nicolas ID (`FL-2026-SN-00001`). Expected: red, another barangay.

### A4. Encoder, San Nicolas (`lub.sannicolas.encoder@familink.test`)
- [ ] Header shows "Barangay San Nicolas". **Residents** lists only the Soriano family (Ramon, Linda, Carlo).
- [ ] The scanner lists "Lubao Relief Packs". Scan Ramon (`FL-2026-SN-00001`). Expected: green. Scan Linda. Expected: red, household claimed.
- [ ] Scan Antonio (Santa Cruz, `FL-2026-SC-00001`). Expected: red, another barangay.

### A5. Encoders, Mexico and San Luis (`mex.sanjosemalino.encoder@familink.test`, `slu.stamonica.encoder@familink.test`)
- [ ] Each header shows its own barangay. **Residents** is empty and the scanner says no relief event is open (there are none for these municipalities yet).
- [ ] Register a first resident in each barangay. Expected: it works, and the new resident shows as waiting for approval.
- [ ] Neither encoder can see the other's resident, or any resident of Bacolor or Lubao.

---

## B. Admin

### B1. Admin, San Jose (`bac.sanjose.admin@familink.test`)

**Login and menu**
- [ ] After login you land on **Approvals**. The header shows "Bacolor: San Jose".
- [ ] When you confirm a suggested **spouse** for a waiting resident, both people become Married and are linked both ways. A spouse from a barangay you do not handle is never linked, and that person's record is not changed.
- [ ] The menu shows: Dashboard, Residents, Approvals, Relief Events, Suggestion Report, Analytics, Users.

**Approvals**
- [ ] The people you registered in A1 are listed, each with details and **View family tree**.
- [ ] Suggested links show for a person with an empty father, mother or spouse slot. Confirm one with **Yes, confirm link**.
- [ ] Click **Unlink** next to a link and confirm. Expected: the link is gone and the person is not deleted.
- [ ] **Approve** one person. Expected: they leave the list and can now have a QR card.
- [ ] **Archive** another and confirm. Expected: they leave the list.
- [ ] Open that approved person under **Residents**: the QR card button now exists.

**Relief events**
- [ ] Open **Relief Events**: the four Bacolor events are shown, and no Lubao event. Events belong to the whole municipality, so the Bacolor admins share them.
- [ ] **New event**: create "Test Aid", Per Resident, start today, end next week, status Draft. Expected: it appears as Draft. You do not see a "Most members per household" box (only a super admin does).
- [ ] **Activate** it. Expected: Active, "Open for scanning".
- [ ] **Edit** the Active event: only name, end date and status can change. The type and start date are locked.
- [ ] **Close** it and confirm. Expected: Closed, "Finished", and no Edit button.
- [ ] Create another Draft and **Delete** it. Expected: gone.
- [ ] Try to delete an Active event: the Delete button is not offered.
- [ ] Open an encoder's scanner: the new Active event appears. The Closed one does not.

**Suggestion report**
- [ ] Open **Suggestion Report**: your Yes / No / Not sure answers from A1 are counted.
- [ ] Change **Time period**: the numbers change. No **Place** choice is offered, because you handle one barangay.

**Analytics**
- [ ] Open **Analytics**: only a San Jose row. Pick an event: the coverage changes.
- [ ] Search a household code in **Households**, then open **Family tree**: a tree with parents above and children below, spouses side by side. Every person has a label under their name (Father, Aunt, Sister...) saying how they are related to the head of the household. Click a person for the full sentence.
- [ ] Use **How far to show**: **Close family** (the default), **+ First cousins** and **+ Second cousins**. Beyond close family only blood relatives are followed, so a cousin's husband or wife is shown but not their family. Try it on a household whose relatives are all registered.
- [ ] Switch between **Tree** and **Relatives** (on a phone it opens on **Relatives**). The list groups people (Parents, Children, Brothers and sisters, Cousins...) closest first, with people related by marriage after them. Type in its search box to narrow by name or relationship; choose **Show on the tree** to jump to that person.
- [ ] The tree opens zoomed on the household, not the whole family. Use **Focus on household** to return and **Fit all** to see everyone.
- [ ] Click a person: everyone else fades and a dark line shows how they connect to the household.
- [ ] If the family is too big, a note says some relatives are not shown. It only says so when blood relatives were cut, not just in-laws.

**Household Review (one family recorded in two households)**
- [ ] Open **Household Review**. A married couple recorded in two households, or a child under 18 living apart from a parent, shows as a pair with the reason in plain words. If your test data has none, record a marriage between two people of different households in B2 first.
- [ ] Pick a relief event: each household shows whether it claimed. A pair where **both** households claimed turns red and is listed first.
- [ ] **Mark as reviewed** with no reason: refused. With a reason: the pair moves to the **Reviewed** tab with your name, the date and the reason.
- [ ] On the **Reviewed** tab choose **Put back on the list**: the pair returns to **To check**.
- [ ] A pair with one household in another barangay shows that household's code and barangay but not its head, and has no **Family tree** link for it.

**Data Check and Relationship Finder**
- [ ] Open **Data Check**. With the seed data it says no problems were found.
- [ ] To see a problem: on a resident's **Update** page set a birthdate that makes a parent younger than the child, or record a marriage on only one person. Reload **Data Check**: the problem is listed in plain words, with **Open** (the person) and **Family tree** buttons. Fix it and reload: it is gone.
- [ ] Open **Relationship Finder**. Type part of a name or a resident ID in **First person** and pick someone from the list, then do the same for **Second person**. It says what each is to the other, such as 'Ana is Ben's aunt' and 'Ben is Ana's nephew'.
- [ ] Try two people from different families: it says no family link was found. **Swap the two people** reverses them, and **Change** picks someone else.

**Staff accounts**
- [ ] Open **Users**: only the encoder(s) of San Jose.
- [ ] **Add account**: another encoder for San Jose. Role choices offered: Encoder only, and only San Jose as the barangay.
- [ ] Try to give that new account a barangay in Cabalantian or Lubao. Expected: not offered or refused.
- [ ] **Deactivate** an encoder, confirm. Expected: "Inactive" label. In the encoder's browser, the next click logs them out, and logging in again says the account is deactivated.
- [ ] At the bottom of **Users**, **Account changes** lists each change with the kind of change, who made it and when. After the steps above you see the creation, the deactivation and the reactivation. Changing an encoder's barangay shows 'Barangay from X to Y'. Setting a password only says 'A new password was set', never the password itself.
- [ ] The list shows 15 changes at a time, newest first. **Older** and **Newer** move between pages and stay on the history. Type a name, an email or a word such as "Barangay" in **Search the changes**, or pick a **Kind of change**: the list narrows and goes back to page 1.
- [ ] Press **History** on an account's card: the list jumps to that account's changes only, with a "Showing the changes of..." notice. **Show all accounts** brings everything back. An admin never sees changes of encoders outside their barangays.
- [ ] An account that has made changes cannot be deleted, only deactivated, so the history keeps its author.
- [ ] **Reactivate** them. Expected: they can log in again.
- [ ] You cannot see or edit admins or super admins here.

**Forbidden for this role**
- [ ] http://localhost:8000/admin/municipalities
- [ ] http://localhost:8000/encoder/register and http://localhost:8000/encoder/scanner
- [ ] A Cabalantian household (same municipality, other admin): http://localhost:8000/family-tree/7 and http://localhost:8000/residents/25/card
- [ ] A Lubao household: http://localhost:8000/family-tree/9 and http://localhost:8000/households/9/cards

### B2. Admin, Cabalantian (`bac.cabalantian.admin@familink.test`)
- [ ] Header shows "Bacolor: Cabalantian". **Approvals**, **Residents** and **Analytics** show only Cabalantian people (Villanueva, Ramos). No Dela Cruz.
- [ ] **Relief Events** shows the same four Bacolor events as the San Jose admin.
- [ ] Open http://localhost:8000/family-tree/1 (San Jose). Expected: forbidden.
- [ ] Open HH-CAB-0001's tree: three generations (Carmen, then Roberto and Elena, then the children).

**Changes after approval (marriage and household moves)**
- [ ] Open **Residents** and click **Update** next to Miguel. The page shows his family, household and an empty history.
- [ ] **Record a marriage**: search "Ramos", choose Teresa, and let her take his surname. Expected: both Married and linked, Teresa shown as "Teresa Villanueva", and a history line on each.
- [ ] Try to marry him to his own sister (Sofia): she is not offered in the search. A child under 16 is not offered either.
- [ ] While a relief event is open (the two Bacolor events are Active), the **Move to another household** button is greyed out and an amber note names the open events. Expected: no move is possible.
- [ ] Close both Bacolor events (and any you created), then **Move to another household**: choose "A household already on record", pick `HH-CAB-0002`, relationship Spouse. Expected: he now lives in `HH-CAB-0002`.
- [ ] Check the earlier relief claims: they still belong to `HH-CAB-0001`.
- [ ] Recording a marriage is still allowed while an event is open (only household moves are paused).
- [ ] Move the head of a household (Roberto): you must choose who becomes the new head.
- [ ] As an encoder, the **Update** button is not shown, and `/admin/residents/27` is forbidden.

**Lost cards, representatives and exports**
- [ ] On Pedro's page (admin), click **Replace QR card**, choose Lost. Expected: a "Replaced QR cards" list appears, the history says the old code no longer works, and **Print QR card** prints the new card.
- [ ] As an encoder, scan the OLD printed code for that resident: red "reported Lost and replaced". The admin page then shows the old code was scanned once. Typing the resident ID is refused too (lost or stolen only); a Damaged card still allows it.
- [ ] As an encoder on the scanner, tick **Someone else is collecting**, leave the fields empty and scan: nothing is recorded and you are told what is missing. Fill in a name, a relationship and the confirmation, then scan: the claim is recorded with who collected it. The box clears for the next person.
- [ ] Have the same person collect for four residents in one event: the fourth result shows an amber warning.
- [ ] As an admin or stakeholder, open **Analytics** and use **Claims (CSV)** and **Barangay summary (CSV)**. Expected: Excel opens them, claims show who collected, and the coverage matches the page.
- [ ] As an encoder or admin, open **Distribution List**: people grouped by household with a signature column, claimed people marked, and the totals for the whole barangay at the top.
- [ ] Type in **Find a person or household** (a name, a resident ID, a household code or a street): it searches by itself after a short pause, and the totals do not change.
- [ ] With more than 10 households, use **Previous** and **Next** to move between pages. Tick "Only people who have not claimed yet" to shrink the list.
- [ ] **Print this page** prints what is on screen. **Print all** loads every household on one sheet, ignores the search, and opens the print window. **Download CSV** always holds the whole list.

**Ending a marriage**
- [ ] On Juan's page (married to Maria), click **End a marriage**, choose Annulled and confirm. Expected: both show Annulled with no spouse, each has a history line, and the children (Pedro) stay linked to both parents.
- [ ] Both can now be recorded as married to someone else with **Record a marriage**. The button was greyed out while Juan still had a spouse.
- [ ] If one of them has a maiden name, a checkbox offers to go back to it. Ticking it changes their surname and the history says what it was before.
- [ ] A spouse who has died is not offered **End a marriage**: that person is widowed instead.

**Deaths and corrections**
- [ ] On a resident page, **Correct registration**: fix a misspelt first name and change the birthdate. Expected: the page updates and the history lists what changed, from and to. Saving with nothing changed, or a birthdate that makes a recorded parent less than 15 years older, is refused.
- [ ] **Record a death** for a married resident (date optional). Expected: a Deceased badge, the spouse becomes Widowed with a history line, and the scanner refuses their QR code. Their past relief claims stay.
- [ ] Record the death of a household head: you must choose a living member as the new head.
- [ ] **Mark as living again** on that resident. Expected: the spouse goes back to Married. The head is not restored automatically.
- [ ] Record a marriage for a widow or widower: the deceased spouse no longer blocks it.
- [ ] Dashboard and analytics counts no longer include the deceased resident.

### B3. Admin, Santa Cruz (`lub.santacruz.admin@familink.test`)
- [ ] Header shows "Lubao: Santa Cruz".
- [ ] **Approvals**, **Residents**, **Analytics** show only Santa Cruz people (the Manalo and Dizon families). No Soriano, no Villanueva, no Dela Cruz.
- [ ] **Relief Events** shows only "Lubao Relief Packs".
- [ ] Open http://localhost:8000/family-tree/1 (San Jose) and http://localhost:8000/family-tree/11 (San Nicolas). Expected: both forbidden.
- [ ] **Users** shows only the Santa Cruz encoder. Adding an encoder offers only Santa Cruz.

### B4. Admin, San Nicolas (`lub.sannicolas.admin@familink.test`)
- [ ] Header shows "Lubao: San Nicolas". Everything shows only the Soriano family.
- [ ] Open http://localhost:8000/family-tree/9 (Santa Cruz). Expected: forbidden. **Relief Events** shows "Lubao Relief Packs", shared with the Santa Cruz admin.
- [ ] Approve the resident registered by the San Nicolas encoder (part A5 style): it works. The Santa Cruz admin cannot see that resident.

### B5. Admins, Mexico and San Luis (`mex.sanjosemalino.admin@familink.test`, `slu.stamonica.admin@familink.test`)
- [ ] Header shows "Mexico: San Jose Malino" and "San Luis: Sta. Monica". Every list starts empty and **Relief Events** is empty.
- [ ] After the encoder in part A5 registers a resident, the matching admin sees them under **Approvals** and can approve them. The other admin cannot see them.
- [ ] Create a relief event for the admin's own municipality. Expected: allowed. The encoder in that barangay then sees it in the scanner, and no one in another municipality does.

---

## C. Stakeholder (`stakeholder@familink.test`)
- [ ] After login you land on **Analytics**. The menu shows Dashboard, Analytics, Household Review, Data Check and Relationship Finder only.
- [ ] All municipalities and barangays appear. The numbers cover everyone.
- [ ] Pick each event: the coverage figures reflect the claims from Part A.
- [ ] A household's **Family tree** opens from any barangay.
- [ ] Open **Household Review**: the same pairs as the admin sees, but there is no **Mark as reviewed** button.
- [ ] **Data Check** lists the same problems, without the **Open** (person) buttons. **Relationship Finder** works across all barangays.
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
- [ ] **Add account**: an admin. Choose the Lubao municipality and tick both barangays. Expected: created. (The system lets an admin handle several barangays of one municipality, even though the test accounts now have one each. Delete this account afterwards.)
- [ ] Try an admin whose barangays are from another municipality than the one chosen. Expected: refused.
- [ ] Add an encoder with exactly one barangay, and a stakeholder.
- [ ] **Edit** your own account: the role cannot change.
- [ ] **Deactivate** yourself: not offered. Deactivate another super admin if there is one.
- [ ] **Delete** an account you just created with no history: deleted.
- [ ] Try to delete the admin who approved residents (`bac.sanjose.admin@familink.test`): the Delete button is hidden, and **Deactivate** is the option.

**Relief events**
- [ ] **New event**, Per Resident: a **Most members per household** box appears. Set it to 2.
- [ ] Choose Per Household: the box disappears.
- [ ] As an encoder, scan three members of one household for a Per Resident event with limit 2: the third is refused.
- [ ] Edit a **Draft** event's limit: allowed for the super admin. Edit an **Active** event (Cash Aid): the limit box is greyed out with a "Locked" note, so nobody can change the rules once a distribution has started.
- [ ] Create, edit and activate an event, then open its **History**: each change is listed with who made it and from what to what (for example "Most members per household 2 to 3").
- [ ] Delete a draft: it appears under **Recently deleted drafts** with who deleted it. As an admin, you only see your own municipality's.
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
- [ ] Hold the same card in front of the camera after it is approved. Expected: no second red 'already claimed' flash while the card stays in view. Scanning a different card works at once.
- [ ] While the camera is on, switch the phone off and on again, or open another app that uses the camera. Back in FamiLink an 'The camera stopped' message appears with a **Restart camera** button, and typing the resident ID still works.
- [ ] Log out in another tab, then scan. Expected: a plain message saying you were signed out and to reload, not a technical error.

---

## Before going live

Do these once, on the computer or host that people will really use. None of them are needed for testing on this PC.

- [ ] In the live .env: APP_ENV=production, APP_DEBUG=false, LOG_LEVEL=error, and APP_URL set to the https:// address. With APP_ENV=production the app already forces secure cookies, encrypted sessions and hides debug pages.
- [ ] Install without the developer tools: composer install --no-dev --optimize-autoloader. This leaves out the Laravel Boost route (_boost/browser-logs) and the Inertia developer tools.
- [ ] If the app sits behind a load balancer or a reverse proxy, set TRUSTED_PROXIES to its address(es), comma separated. If you leave it out, the app trusts only this computer, so a visitor cannot fake their address to get around the login lockout.
- [ ] Do not leave public/hot on the live server (it only exists while 
pm run dev runs). Run 
pm run build instead.
- [ ] Set up a real mail service in .env (MAIL_MAILER, host, user, password), or tell staff that **Forgot your password** will not send an email. Right now emails are only written to the log file.
- [ ] Make a backup before the first real use (see below) and decide who makes one each day.

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
php artisan db:seed
php artisan db:seed --class=WalkthroughSeeder
```
The first command creates the base accounts and the San Jose family. The second creates the other municipalities and barangays, **an admin and an encoder for every barangay** (each admin handles one barangay), the Cabalantian, Santa Cruz and San Nicolas families, and the relief events.

Running them again is safe: nothing is duplicated, an account that already exists is left alone (a changed name or password is **not** reset), and no barangay is taken away from an admin. They do not reset residents you approved or events you closed. To get the original state again, restore the backup.

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
