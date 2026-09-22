RENEWAL CENTER - going live on the DigitalOcean droplet
=======================================================
Same shape as SEV Center and Sync Center: one git clone under
/var/www/apps, Hub login from ../core/auth.php, database from
../config/db.php, deploy = git pull on every merge to main.

BEFORE YOU START (GitHub, 2 minutes)
  1. github.com/opmhiowner/rnw > Settings > Secrets and variables >
     Actions > New repository secret, three times:
       DO_HOST     the droplet address (same value as the sev repo)
       DO_USER     same as sev
       DO_SSH_KEY  same as sev
  2. Optional but recommended: Settings > General > Danger zone >
     Change visibility > Private, to match sev / sync / hub.
     If you do, the droplet needs the same deploy key the other
     clones use (see step D).

ON THE DROPLET (ssh as the same user the other apps use)

A. Clone next to the other apps
     cd /var/www/apps
     git clone https://github.com/opmhiowner/rnw.git
     ls rnw           # index.php media.php comps.php lib/ api/ ...
   The folder is "renewal" (not "rnw") so the URL is /rnw/.

B. Ownership, same as sev
     chown -R www-data:www-data /var/www/apps/rnw
     ls -la /var/www/apps/core/auth.php /var/www/apps/config/db.php
   Both must already exist (they do - SEV and Sync use them).
   /var/www/apps/config/sync.php must be readable by www-data too:
   the app reads crypto_key_b64 from it to reuse Sync Center's
   Rentvine credentials. If that file is 640 root:root, run
     chgrp www-data /var/www/apps/config/sync.php
     chmod 640 /var/www/apps/config/sync.php

C. Apache
   If the vhost's DocumentRoot is /var/www/apps (how sev at /sev/
   and sync at /sync/ are served), nothing to add: /rnw/ works
   as soon as the folder exists. Check with
     grep -rn "apps" /etc/apache2/sites-enabled/
   If the other apps are wired with Alias blocks, add the same for
   rnw in that vhost:
     Alias /rnw /var/www/apps/rnw
     <Directory /var/www/apps/rnw>
         AllowOverride All
         Require all granted
     </Directory>
     <DirectoryMatch "^/var/www/apps/rnw/(lib|\.git|design|reference|launcher)">
         Require all denied
     </DirectoryMatch>
   then
     apachectl configtest && systemctl reload apache2
   The app's own .htaccess is the second lock (denies lib/, .git,
   design/, reference/, launcher/, README*, CLAUDE.md, *.json).

D. Let the GitHub Action pull
   The workflow runs "cd /var/www/apps/rnw && git pull --ff-only
   origin main" over ssh. With a public repo the https clone from
   step A pulls with no key. If the repo is private, point the clone
   at the same ssh remote + deploy key the sev clone uses:
     cd /var/www/apps/sev && git remote -v      # copy the pattern
     cd /var/www/apps/rnw && git remote set-url origin git@github.com:opmhiowner/rnw.git
   Then push any commit to main (or Actions > Deploy Renewal Center >
   Run workflow) and watch it go green.

E. First load (browser, signed in to the Hub)
     https://apps.oishis.net/rnw/probe.php
   Prints the office, whether sync_leases / sync_records are seen,
   one lease as Sync Center holds it, and the fields the app derived.
   If rent / end / deposit / mtm are blank -> the Rentvine key names
   differ; send that page's output and lease_join() gets the names.
     https://apps.oishis.net/rnw/probe.php?queue=1
   The computed queue with the reason for every row.
     https://apps.oishis.net/rnw/
   Tables create themselves on this first load - no SQL to run.

F. Settings (button on Main, once per office)
   - Rentvine: the strip at the top says which credentials are in
     use ("Sync Center source for this office" is what you want).
     Press "Send test" on any record: it lists the lease's recurring
     charges (rent charge marked RENT) and the GL accounts that look
     like rent / deposit with their ids. Put the deposit account id
     in "Security deposit GL account id". The rent account id is
     learned from the live rent charge on the first post.
   - Custom field id = 3 (Last Renewal Date), already the default.
   - Review window / MTM months / steps / deposit rule as the office
     likes. Display mode on or off by default.

G. Hub tile
   Add Renewal Center to the Hub's app list (hub repo) pointing at
   /rnw/ so staff open it from the portal like SEV.

H. Each PC (launcher/INSTALL.md)
   Unzip renewal-launcher-0.1.zip to C:\OishiApps\, set the photo
   drive folder in photo-agent.ps1, monitor numbers in
   launch-renewal.ps1, Send to > Desktop. Sign in to the Hub once in
   each of the three windows.

FIRST REAL POST
   Pick one lease you can check in Rentvine. Save the decision,
   press "Preview the 4 steps" (nothing is sent), read the URLs and
   bodies, then "Post now". Then look at the lease in Rentvine: old
   rent charge ended the day before the new start, new rent charge
   from the start date, deposit charge on the ledger, Last Renewal
   Date = the rent start date. Activity on the record shows every
   reply Rentvine gave.

ROLLBACK
   cd /var/www/apps/rnw && git log --oneline -5 && git checkout <sha>
   (tables are additive; an older build ignores newer columns).
