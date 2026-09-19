# Renewal Center launcher v0.1 — install (one PC, ~3 minutes)

What it does: double-click one icon → three Chrome windows open, one per monitor/TV,
full screen: **Media** (left), **Main** (center), **Comps** (right), plus the small
**photo agent** that shows the office photo drive in the Media window.
Clicking a renewal on Main flips the other two automatically (within 2 seconds).

## 1. Copy the files

| App | Step |
|---|---|
| File Explorer | Unzip `renewal-launcher-0.1.zip`. Copy the folder `renewal-launcher-0.1` to `C:\OishiApps\` (create the folder if it does not exist). You should now have `C:\OishiApps\renewal-launcher-0.1\launch-renewal.ps1`, `photo-agent.ps1` and `Renewal Center.bat`. |

## 2. Find the monitor numbers on this PC

| App | Step |
|---|---|
| Windows Settings | Press **Windows key + I** → left menu **System** → **Display**. |
| Windows Settings | Click the **Identify** button (under the monitor picture). A big number appears on each screen for a few seconds. Write down which number is on the left, center and right screen. |

## 3. Set the monitor order (only if it is not 1-2-3 left-to-right)

| App | Step |
|---|---|
| File Explorer | Right-click `launch-renewal.ps1` → **Edit** (opens Notepad). |
| Notepad | Find the block that starts `$Layout = @{`. Change the three numbers so **Media** = left screen number, **Main** = center, **Comps** = right. Save with **Ctrl + S** and close. |

## 3b. Point the photo agent at the photo drive (once per PC)

| App | Step |
|---|---|
| File Explorer | Right-click `photo-agent.ps1` → **Edit**. |
| Notepad | In the CONFIG block set `$PhotoRoot` to the folder that holds one folder per property (for example `\\server\photos` or `P:\Photos`) and, if the property folders are not named exactly by property code, `$FolderRule` (for example `{pcode}\Listing`). Save and close. |
| Media window | After the first run, the left pane says where it looked (`\\server\photos\<code>`). No photos → check that path exists for that property code. |

## 4. Make the Desktop icon

| App | Step |
|---|---|
| File Explorer | Right-click `Renewal Center.bat` → **Show more options** (Windows 11 only) → **Send to** → **Desktop (create shortcut)**. |
| Desktop | Optional rename: right-click the new icon → **Rename** → type `Renewal Center`. |

## 5. First run

| App | Step |
|---|---|
| Desktop | Double-click **Renewal Center**. Three windows open. If any lands on the wrong screen, go back to step 3 and swap the numbers. |
| Chrome (each window) | First time only: sign in to the Hub when prompted **in all three windows**. Each window remembers its login after that. The Media and Comps windows follow Main once they are signed in as the same user. |

## Meeting room / TVs

Same steps. If the TVs are far away, the app's **Display mode** (larger type) is on by
default; it can be turned off per PC from Main → Settings later.

## Closing

Close each window normally (**Alt + F4**), or just leave them — they reconnect on the
next click. Re-run the icon any time; it does not open duplicates on top of open windows
if you closed them first.

## If Chrome is not installed

The script says `Chrome not found`. Install Google Chrome (https://www.google.com/chrome/)
and run the icon again.
