# M4 acceptance record

Current drill date: October 17, 2026, America/Chicago. Release `401d6a233c54893cfa35529ffadd72a35d7c9e9c`. This record distinguishes observed passes from work that needs future time or owner participation. M4 is not complete; M5 has not started. Research and all brokerage-write/legacy-trading flags remain disabled.

## Observed checks

| Check | Evidence | Status |
|---|---|---|
| Restricted runtime login | `postgres` / `mcp_trading`, login `mcp_trading_runtime`, owner `admin`; all health checks passed before drill | Pass |
| Worker restart and duplicate protection | Earlier deployed replay retained one snapshot/run; repeated PostgreSQL concurrency test passed | Pass |
| Runtime run reconciliation | Seven complete runs; zero duplicate logical keys, duplicate snapshots, complete runs without a complete snapshot, or failed jobs | Pass at audit time |
| Current-secret log scan | Ten current credential/key/topic/heartbeat values, including decrypted OAuth tokens and backup key, checked literally and URL-encoded in 19 runtime log files; zero matching files | Pass within stated scan scope; historical/unknown secrets are not proven absent |
| Local backup/restore | Earlier real 54-table isolated restores passed with readable encrypted credentials and disabled restored policy; current isolated restore tests passed | Pass |
| ntfy operational incident | `health:scheduler` opened critical at 12:39:16 CDT; ntfy provider acknowledged one incident delivery at 12:39:17 | Pass for provider receipt; earlier phone test receipt owner-confirmed |
| Healthchecks configuration and outage detection | Corrected 1 day + 1 hour grace to 1 minute + 10 minutes; independent Down email reached the owner's Gmail inbox at 12:46 CDT with reason success signal did not arrive on time | Pass for actual missing-ping detection and inbox receipt |

## Controlled scheduler outage

At **12:36:03 CDT / 17:36:03 UTC**, only the scheduler LaunchAgent was booted out. Nginx, PHP-FPM, PostgreSQL and all three workers stayed running. This did not disconnect the Mac from the internet. Last pre-drill heartbeat was 12:35:29 CDT. A separate launchd recovery guard was installed first: it bootstraps the original scheduler after 900 seconds even if this assistant stops. Manual health observations used `--no-ping` so they could not conceal the missing heartbeat. The scheduler became critical and produced the incident/delivery above. No business event was replayed to fill the intentional gap.

Healthchecks independently transitioned from late to Down after the actual missing-ping interval. At 12:46 CDT the owner's Gmail inbox received **DOWN | My First Check**, with reason **success signal did not arrive on time**. No manual `/fail` signal or Ping Now action was used. The original scheduler was restored at **12:47:24 CDT / 17:47:24 UTC**; its real scheduled health task ticked and received a successful heartbeat acknowledgement at **12:47:25 CDT**. The local `health:scheduler` incident resolved at that same time, retaining exactly one notified episode. Every current health check passed. The temporary recovery guard was booted out and removed only after scheduler restoration was verified; the six persistent services remain. Intentional scheduler pause: approximately 11 minutes 20 seconds. The owner's Gmail inbox also received **UP | My First Check** at 12:47 CDT, reporting **55 seconds** in the Down state. This shorter figure excludes the pre-alert grace interval; it is not the total scheduler pause. Both emails were visibly verified in the authenticated inbox without reading login tokens or changing mailbox state.

## Cloud recovery

The operator encountered an iCloud sign-in connection error. Apple's status page listed iCloud sign-in, Drive and web apps available at 12:41 CDT; this does not rule out an individual account/device/network problem. No Apple account setting was changed. Web download/remote recovery remains unverified. Alternative owner step: download an encrypted backup and matching manifest through the iPhone Files app, then AirDrop both to the Mac for hash validation and an isolated restore of those downloaded bytes. A successful read of the Mac's local sync folder is insufficient.

Follow-up: the owner confirmed that they can see and download the backup and manifest through the iPhone Files app. This is owner-reported independent-device availability; the downloaded bytes have not yet been received in this Mac's Downloads folder. Remote-copy hash validation and isolated restore remain pending receipt of the two AirDropped files.

## Calendar coverage and proving window

Reviewed primary sources: [NYSE 2026 calendar](https://www.nyse.com/publicdocs/nyse/ICE_NYSE_2026_Yearly_Trading_Calendar.pdf) and [NYSE hours/holiday publication](https://www.nyse.com/trade/hours-calendars). The actual downloaded PDF SHA-256 is `70f5577eb43e60a9dbbecaae3cec23d0f02028c05c7f175013bb3e97816d394f`. Seven explicit sessions were loaded using the existing `MarketCalendar::store`; their exact dates/times and provenance are in [the reviewed manifest](calendars/nyse-2026-10-proving.json). No weekday fallback or perpetual calendar was introduced, and no policy/mode changed.

Source retrieval was **October 17, 17:41:21 UTC**. Existing freshness rules expire it **October 24, 17:41:21 UTC**. Re-fetch/review both primary sources and refresh the explicit session records before October 26; do not simply change timestamps without checking the sources. No automatic calendar provider is configured. Dates outside this manifest, stale dates and weekends without a verified row remain unknown and retain hourly observation. Regular covered sessions use the existing five-minute cadence.

Read-only checks against the actual imported rows verified October 19 before-open/open/close boundaries and unknown results for October 24 after freshness expiry, October 26 without a revalidation, and unlisted October 28. Time simulation stayed inside that read-only process; the Mac clock and runtime records were not changed by those checks.

| Planned session (New York) | Availability / complete expected slots | Missing or duplicate IDs | Stale/source coverage | Incidents / recovery | Recorded AI/data costs | Result |
|---|---|---|---|---|---|---|
| October 19 | Not observed | Not observed | Not observed | Not observed | Not observed | Pending prerequisites and actual session |
| October 20 | Not observed | Not observed | Not observed | Not observed | Not observed | Pending |
| October 21 | Not observed | Not observed | Not observed | Not observed | Not observed | Pending |
| October 22 | Not observed | Not observed | Not observed | Not observed | Not observed | Pending |
| October 23 | Not observed | Not observed | Not observed | Not observed | Not observed | Pending |
| October 26 | Not observed | Not observed | Calendar must be reverified/refreshed first | Not observed | Not observed | Pending |
| October 27 | Not observed | Not observed | Calendar must be reverified/refreshed first | Not observed | Not observed | Pending |

These dates are an earliest candidate window, not a claim that proving has begun. Start only after outstanding recovery/alert checks and fresh calendar coverage are ready. For a full regular session, expect 78 distinct five-minute monitoring slots per bound account between 09:30 and 16:00 New York; additionally inspect reconciliation jobs for unresolved orders. Record partial/missed slots instead of manufacturing catch-up observations. Source timestamp/flow/quote/classification gaps remain explicit; a complete pagination snapshot is not a claim that unavailable fields are known. Research is disabled and no budget rows exist at the current audit; that does not establish an external invoice total. Restart the window after a material reliability defect.

## Owner-assisted Mac drills

The owner agreed to perform these after the current checks. Save work first. Run one drill at a time and verify recovery before the next:

1. Disconnect networking briefly, then reconnect; verify services, heartbeat, a fresh read-only reconciliation and no duplicate event.
2. Sleep/wake the Mac using its actual lid/power setup; record the gap and resume behavior.
3. Log out/in; confirm user LaunchAgents reload. No operation while logged out is promised by this installation.
4. Reboot, unlock FileVault if prompted, and log in; verify PostgreSQL, web services, workers, scheduler, fresh snapshot and heartbeat. The current installation does not promise pre-login operation.

Current AC idle sleep is 0; battery sleep is 10 minutes. Neither setting was changed. A successful process restart is not a substitute for these actual Mac drills.

The initial network observation window ran **12:48:03–12:51:01 CDT**, recording 48 samples. Every public internet request and local readiness request succeeded; no actual disconnection was observed. This is a baseline, not a passed network-outage test. The observer has exited; it did not change networking. Actual network, sleep/wake, logout/login and reboot tests are still pending owner action. All six runtime services and current health checks were verified normal afterward. Private raw observations remain in `~/Services/investing-ops/runtime/network-acceptance.jsonl`.

**Network follow-up passed with stated evidence scope.** The owner subsequently reported reconnection. At initial verification the real scheduler had recorded heartbeat **unreachable at 13:09:56 CDT**; public connectivity and loopback readiness then returned HTTP 200, and the real scheduled heartbeat was **acknowledged at 13:10:58 CDT**. All six services remained running and all current health checks passed. A new read-only reconciliation completed at **13:11:30 CDT**, run `11cd1b03-c6ea-4eec-ade4-f9dd08e8e3ed`. Replaying its stable key returned the same run, with exactly one complete snapshot and zero failed jobs. Writes remained disabled. The earlier observation window did not capture the cut itself; its duration comes from the owner action, not a fabricated probe trace. The later recorded unreachable heartbeat plus successful recovery are actual application observations. Private verification is in `~/Services/investing-ops/runtime/network-recovery.json`. Sleep/wake, logout/login and reboot are still pending.

**Sleep/wake passed.** After the owner reported Awake, macOS power logs confirmed actual sleep at **13:12:24 CDT** and wake at **13:12:44 CDT** on October 17. The boot identity was unchanged. All six services were present, web readiness and operational health passed, and an actual scheduled tick/heartbeat was acknowledged at **13:13:17 CDT**, after wake. A fresh read-only broker reconciliation completed at **13:13:35 CDT**, run `2ac573ac-2bf4-48c0-beff-363f5d2190a8`; same-key replay retained exactly one run and one complete snapshot, with zero failed jobs and writes disabled. Private power/reconciliation observations are in `~/Services/investing-ops/runtime/sleep-recovery.json` and `sleep-reconciliation.json`. This verifies the observed twenty-second sleep/wake cycle on the current setup, not an overnight/lid-closed or battery-duration guarantee. Logout/login and reboot remain unperformed.

Resume checkpoint before logout: current runtime is `~/Services/investing-ops/app`, commit `401d6a233c54893cfa35529ffadd72a35d7c9e9c`; source documentation changes are intentionally uncommitted. Runtime credentials remain restricted and read-only broker monitoring is enabled; research, legacy trading and broker writes are disabled. The original scheduler is restored, with no temporary recovery guard. After the owner logs in again, verify all six LaunchAgents, PostgreSQL, `/ready`, real new scheduler/heartbeat observations and one fresh scoped read before marking logout/login passed. Compare actual boot identity if claiming a reboot. Do not mark a reboot based only on new worker PIDs. Owner-reported iPhone download availability is recorded, but no returned backup/manifest has yet appeared in Downloads, so remote restore remains pending.

For remote recovery, also confirm that both `APP_KEY` and `~/.config/investing-ops/backup.key` are recoverable independently of this Mac, for example in the owner's password manager. They remain outside the cloud backup folder. Do not paste either key into chat or put it beside the encrypted dumps.

## Tests in this acceptance pass

Composer validation passed. PostgreSQL operational failure, isolated restore and monitoring concurrency tests: **14 tests / 119 assertions**. Existing calendar holiday/early-close/DST test: **1 test / 6 assertions**. Tests used guarded `mcp_trading_test` and fake external clients. No application migration or frontend change was needed.
