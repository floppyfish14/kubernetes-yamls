---
title: "Gentoo Secure Cluster"
date: 2019-03-28T17:01:02-07:00
draft: false
---
1) Download miminal [gentoo iso](https://www.gentoo.org/downloads/)
-----
2) DD image to a usb
-----
This command will overwrite data that is on your usb if you have not formatted it.
```bash
sudo dd if=install-amd64-minimal.iso of=/dev/sdX conv=sync,noerror bs=1024
```
*Note that /dev/sdX refers to the block designation of the usb. Yours will be different*

3) Install gentoo
-----
Use the usb to boot into your intended computer. Typically, BIOSs have a boot menu option enabled which you can access by rapidly pressing the <f12> key at bootup. After you've enter your boot menu, select the option to boot from USB.

This screen will appear after you've selected the image to boot.
*add a gentoo boot image*

Now you should configure your networking!

```
add code for networking once you've figured it out
```

### Partition the Hard Drive ###

```
livecd ~# gdisk /dev/sdX
GPT fdisk (gdisk) version 1.0.3
... additional output suppressed ...
Command (? for help): n
Partition number (1-128, default 1): 1
First sector: [Enter]
Last sector: 100Mi
Hex code or GUID: ef00
Command (? for help): n
Partition number: 2
First sector: [Enter]
Last sector: [Enter]
Hex code or GUID: 8e00
Command (? for help): w

Final checks complete. About to write GPT data. THIS WILL OVERWRITE EXISTING PARTITIONS!!

Do you want to proceed? (Y/N): y
OK; writing new GUID partition table (GPT) to /dev/sdX.
The operation has completed successfully.
```
The next step will format the partition using LUKS (Linux Unified Key Setup). The whole /dev/sdX2 partition will be encrypted with a high entropy key, the *master key*. This key is then encrypted using between one and eight *user* keys. The user keys are pre-processed by [PBKDF2](Some link). Luks does not care about the symmetric encryption method used, as long as it is supported by [dm-crypt](some link) it will use whatever encryption method you supply. To the currently supported encrypt and hash algorithms use:
```
livecd ~# cat /proc/crypto
```

Next we will encrypt the newly made LVM partition.
```
livecd ~# cryptsetup --cipher serpent-xts-plain64 --key-size 512 --hash whirlpool luksFormat /dev/sdX2

WARNING!
========
This will overwrite data on /dev/sdX2 irrevocably.

Are you sure? (Type uppercase yes): YES
Enter passphrase: <your passphrase>
Verify passphrase: <your passphrase>
livecd ~#
```
Check that the formatting worked:
```
livecd ~# cryptsetup luksDump /dev/sdX2
```
**If the LUKS header get damaged, your encrypted data will be lost forever. You should backup your header.**

Structure your LVM
-----
Talk about PV
Talk about PE
Talk about VG
Talk about LV

livecd ~# cryptsetup luksOpen /dev/sdx3 gentoo
Enter passphrase for /dev/sdX2: <your passphrase>

livecd ~# pvcreate /dev/mapper/gentoo
*If you see a warning that says:*
```
/run/lvm/lvmetad.socket: connect failed: No such file or directory
WARNING: Failed to connect to lvmetad. Falling back to internal scanning.
```
*it may be ignored.*

Now we create a volume group for the PV.
```
livecd ~# vgcreate <vgname> /dev/mapper/gentoo
```
Create a swap partition.
Size your Swap according to the size of your RAM. You can see your total RAM size using:
```
livecd ~# grep MemTotal /proc/meminfo
```
Create the logical volumes.
```
livecd ~# lvcreate --size 10G --name swap <vgname>
livecd ~# lvcreate --size 50G --name root <vgname>
livecd ~# lvcreate --extents 95%FREE --name home <vgname>
livecd ~# pvdisplay
livecd ~# vgdisplay
livecd ~# lvdisplay
```
Now active your voluem group so that the logical volumes become available as block devices for formatting:
```
livecd ~# ls /dev/mapper
control gentoo <vgname>-home <vgname>-root <vgname>-swap
livecd ~# mkswap -L "swap" /dev/mapper/<vgname>-swap
livecd ~# mkfs.ext4 -L "root" /dev/mapper/<vgname>-root
livecd ~# mkfs.ext4 -L "home" -m 0 /dev/mapper/<vgname>-home
livecd ~# swapon -v /dev/mapper/<vgname>-swap
livecd ~# mount -v -t ext4 /dev/mapper/<vgname>-root /mnt/gentoo
```

### Create and Mount Needed Directories ###
```
livecd ~# mkdir -v /mnt/gentoo/{home,boot,boot/efi}
livecd ~# mount -v -t ext4 /dev/mapper/<vgname>-home /mnt/gentoo/home
livecd ~# umount -v /tmp/efiboot
```
Now we can setup our server with a stage3 tarball.
*We will set a variable inside of bash to pull down the most recent realease from gentoo*

```
livecd ~# cd /mnt/gentoo
livecd /mnt/gentoo # DATE=$(curl http://distfiles.gentoo.org/releases/amd64/autobuilds/latest-stage3-amd64.txt | grep .xz| cut -d'/' -f1)
livecd /mnt/gentoo # wget -c http://distfiles.gentoo.org/releases/amd64/autobuilds/$DATE/stage3-amd64-$DATE.tar.xz
livecd /mnt/gentoo # tar -xvjpf stage3-amd64.*.tar.xz --xattrs-include='*.*' --numeric-owner
livecd /mnt/gentoo # ls
```
You should see the base gentoo files have populated inside of /mnt/gentoo.
```
livecd /mnt/gentoo # rm -v -f stage3-amd64-*
livecd /mnt/gentoo # cd ~
```
Gentoo, Portage, Ebuilds and emerge
-----
*Talk about these things in depth.*

Configure /etc/porttage/make.conf
-----
Use your preferred editor to configure your system.

```
livecd ~ # vi /mnt/gentoo/root/.bashrc

export NUMCPUS=$(nproc)
export NUMCPUSPLUSONE=$(( NUMCPUS + 1 ))
export MAKEOPTS="-j${NUMCPUSPLUSONE} -l${NUMCPUS}"
export EMERGE_DEFAULT_OPTS="--jobs=${NUMCPUSPLUSONE} --load-average=${NUMCPUS}"
```
Save and exit.

*Talk about parrallel compiling and what to do if it doesn't work*

Ensure the .bashrc file is picked up by root's login shell; copy it to the default .bash_profile
```
livecd ~ # cp -v /mnt/gentoo/etc/skel/.bash_profile /mnt/gentoo/root/
```
Edit /mnt/gentoo/etc/portage/make.conf to read:
```
# Build setup as of <add current date>

# C and C++ compiler options for GCC.
CFLAGS="-march=native -O2 -pipe"
CXXFLAGS="${CFLAGS}"

# Note: MAKEOPTS and EMERGE_DEFAULT_OPTS are set in .bashrc

# Only free software, please.
ACCEPT_LICENSE="-* @FREE CC-Sampling-Plus-1.0"

# WARNING: Changing your CHOST is not something that should be done lightly.
# Please consult http://www.gentoo.org/doc/en/change-chost.xml before changing.
CHOST="x86_64-pc-linux-gnu"

# Use the 'stable' branch - 'testing' no longer required for Gnome 3.
# NB, amd64 is correct for both Intel and AMD 64-bit CPUs
ACCEPT_KEYWORDS="amd64"

# Additional USE flags supplementary to those specified by the current profile.
USE=""
CPU_FLAGS_X86="mmx mmxext sse sse2"

# Important Portage directories.
PORTDIR="/usr/portage"
DISTDIR="${PORTDIR}/distfiles"
PKGDIR="${PORTDIR}/packages"

# This sets the language of build output to English.
# Please keep this setting intact when reporting bugs.
LC_MESSAGES=C

# Turn on logging - see http://gentoo-en.vfose.ru/wiki/Gentoo_maintenance.
PORTAGE_ELOG_CLASSES="info warn error log qa"
# Echo messages after emerge, also save to /var/log/portage/elog
PORTAGE_ELOG_SYSTEM="echo save"

# Ensure elogs saved in category subdirectories.
# Build binary packages as a byproduct of each emerge, a useful backup.
FEATURES="split-elog buildpkg"

# Settings for X11
VIDEO_CARDS="intel i965"
INPUT_DEVICES="libinput"
```
*Talk about different values for VIDEO_CARDS*

Build your systemd Gentoo Base (Minus Kernel)
-----
Select the appropriate server URI's for Portage to search through when looking for source tarballs.
```
livecd ~ # mirror select -i -o >> /mnt/gentoo/etc/portage/make.conf
<select all mirrors in your region>
livecd ~ # tail /mnt/gentoo/etc/portage/make.conf
<check that the appropriate enteries were written to make.conf>
livecd ~ # ls -d /mnt/gentoo/var/db/pkg/sys-apps/portage-*
/mnt/gentoo/var/db/pkg/sys-apps/portage-2.3.62
```
Ensure your version of portage is suffienctly recent. At the time of writing (29Mar19), the portage version was 2.3.62.

Next, setup a gentoo.conf file:
```
livecd ~ # mkdir -p -v /mnt/gentoo/etc/portage/repos.conf
livecd ~ # cp -v /mnt/gentoo/usr/share/portage/config/repos.conf /mnt/gentoo/etc/portage/repos.conf/gentoo.conf
livecd ~ # vi /mnt/gentoo/etc/portage/repos.conf/gentoo.conf
```
/mnt/gentoo/etc/portage/repos.conf/gentoo.conf should read:
```
[DEFAULT]
main-repo = gentoo
sync-allow-hardlinks = yes

[gentoo]
location = /usr/portage
sync-type = webrsync
#sync-type = rsync
sync-uri = rsync://rsync.gentoo.org/gentoo-portage
sync-webrsync-verify-signature = true
auto-sync = yes

sync-rsync-verify-jobs = 1
sync-rsync-verify-metamanifest = yes
sync-rsync-verify-max-age = 24
sync-openpgp-key-path = /usr/share/openpgp-keys/gentoo-release.asc
sync-openpgp-key-refresh-retry-count = 40
sync-openpgp-key-refresh-retry-overall-timeout = 1200
sync-openpgp-key-refresh-retry-delay-exp-base = 2
sync-openpgp-key-refresh-retry-delay-max = 60
sync-openpgp-key-refresh-retry-delay-mult = 4
```
*Talk about settings and their meanings*

Ensure DNS is carried over to our chroot envrionment by issuing:
```
livecd ~ # cp -v -L /etc/resolv.conf /mnt/gentoo/etc/
```
**If installing via wifi**
```
livecd ~ # cp -v /etc/wpa.conf /mnt/gentoo/etc/
```
Mount some needed system directories for the chroot environment.
```
livecd ~ # mount -v -t proc none /mnt/gentoo/proc
livecd ~ # mount -v --rbind /sys /mnt/gento/sys
livecd ~ # mount -v --rbind /dev /mnt/gentoo/dev
```
Now we ensure that /sys and /dev are bind mounted as slaves. *Explain why*
```
livecd ~ # mount -v --make-rslave /mnt/gentoo/sys
livecd ~ # mount -v --make-rslave /mnt/gentoo/dev
```
Enter chroot
-----
*Give some background and overview of chroot and what we will be doing*
```
livecd ~ # chroot /mnt/gentoo /bin/bash
livecd ~ # source /etc/profile
livecd ~ # export PS1="(chroot) $PS1"
```
*Explain difference between ssh and physical connect chroot*

Install an Up-to-Date Portage Tree
-----
*Overview process of creating portage tree*
```
(chroot) livecd / # emaint sync --auto
>>> Syncing repository 'gentoo' into '/usr/portage'...
 * Using keys from /usr/share/openpgp-keys/gentoo-release.asc
 * Refreshing keys from keyserver ...                                    [ ok ]
Fetching most recent snapshot ...
Trying to retrieve YYYYMMDD snapshot from <a_local_mirror> ...
Fetching file portage-YYYYMMDD.tar.xz.md5sum ...
Fetching file portage-YYYYMMDD.tar.xz.gpgsig ...
Fetching file portage-YYYYMMDD.tar.xz ...
Checking digest ...
Checking signature ...
gpg: Signature made <a_timestamp>
gpg:                using RSA key <a_key_id>
gpg: Good signature from "Gentoo ebuild repository signing key (Automated Signing Key) <infrastructure@gentoo.org>" [unknown]
gpg:                 aka "Gentoo Portage Snapshot Signing Key (Automated Signing Key)" [unknown]
gpg: WARNING: Using untrusted key!
Getting snapshot timestamp ...
Syncing local tree ...
... additional output suppressed ...
Action: sync for repo: gentoo, returned code = 0
```
You should receive a code of 0 after the sync has completed. *Explain what just happened, and why the keys were 'untrusted'.*
Ignore the prompt to read news articles for now.

Now that we have a base under our feet, switch to the more effienct rsync protocol by issuing:
```
(chroot) livecd / # nano -w /etc/portage/repos.conf/gentoo.conf
#sync-type = webrsync
sync-type = rsync
(chroot) livecd / # emaint sync --auto
>>> Syncing repository 'gentoo' into '/usr/portage'...
 * Using keys from /usr/share/openpgp-keys/gentoo-release.asc
 * Refreshing keys from keyserver ...                                    [ ok ]
>>> Starting rsync with rsync://<ip_addr>/gentoo-portage...
... additional output suppressed ...
Action: sync for repo: gentoo, returned code = 0
```
*Explain reasoning behind -w flag passed to nano*
*Explain how rsync works*

Our next step is to ensure portage itself is up to date now that we have a known-valid current Portage ebuild.
```
(chroot) livecd / # emerge --ask --verbose --oneshot portage
... additional output suppressed ...
Would you like to merge these packages? [Yes/No] <press y, then press Enter>
... additional output suppressed ...
```
*Talk about what's going on underneath the hood*

### Read the news ###
```
(chroot) livecd / # eselect news list
(chroot) livecd / # eselect news read N <replace N with a number from the last command>
```
**OR**
```
(chroot) livecd / # eslect news purge
```
Personal preference is key here. Just remember, those informed stay wise.

### Ensure a Base Profile is set ###
*Explain profiles and what they do*
```
(chroot) livecd / # eselect profile list | less
(chroot) livecd / # eselect profile set N <ensure N corresponds to "default/linux/amd64/17.0">
```
### Set Timezone and Locale ###
```
(chroot) livecd / # ls /usr/share/zoneinfo
```
Choose the appropriate location, for example to set Los Angeles in the US:
```
(chroot) livecd / # echo "America/Los_Angeles" > /etc/timezone
```
Now we reconfigure the sys-libs/timezone-data package; this will pull the value from /etc/timezone and reflect that value in /etc/localtime. This is necessary for the system C library.
```
(chroot) livecd / # emerge -v --config sys-libs/timezone-data
```
If the appropriate locale is listed for you in the file, simply uncomment it. Otherwise, you will need to *append* the locale to the end of the file. For example:
```
(chroot) livecd / # nano -w /etc/locale.gen
en_GB ISO-8859-1
en_GB.UTF-8 UTF-8
```
Save and exit. *Explain why you should add a UTF-8 entry*
*Give a link for more information on locales*

Now we must generate our locales. Issue:
```
(chroot) livecd / # locale-gen
```
This takes the values from /etc/local.gen and uses them to create the locales.

*To see a list of currently installed locales*
```
(chroot) livecd / # eslect locale list
  [1]   C
  [2]   en_GB
  [3]   en_GB.iso88591
  [4]   en_GB.utf8
  [5]   POSIX
  [ ]   (free form)
```
If following this guide using **ssh/screen**, issue:
```
(chroot) livecd / # eselect locale set "C"
```
because other setups can cause issues. We will switch the locale later on.

Now reload your environment:
```
(chroot) livecd / # env-update && source /etc/profile && export PS1="(chroot) $PS1"
```
Setting Up (Post-Boot) Keymap
-----
*Explain difference between initramfs keymap and post-boot keymap and why we set them*
To search for qwerty keyboard mappings, issue:
```
(chroot) livecd / # ls /usr/share/keymaps/i386/qwerty
```
Choose your keymapping and set it using:
```
(chroot) livecd / # nano -w /etc/conf.d/keymaps
keymap="ru"
```
Save and exit. This would set a Russian keymapping. **Set the appropriate keymapping for you**
### Set Processor-Specific Features (Optional) ###
Currently we have default CPU_FLAGS_x86 set. If you would like to use the specific features of your CPU, execute the following:
```
(chroot) livecd / # emerge --verbose --oneshot app-portage/cpuid2cpuflags
(chroot) livecd / # cpuid2cpuflags
(chroot) livecd / # nano -w /etc/portage/make.conf
CPU_FLAGS_X86="CPU_FLAGS_X86: aes avx avx2 fma3 mmx mmxext popcnt sse sse2 sse3 sse4_1 sse4_2 ssse3"
```
Your values will most likely differ. Save and exit.

### Prepare to Run Parallel emerges ###
*Explain why we need zzz_via_autounmask*
*Thank Sakaki for her github awesomeness*

Install git since we will need it to install zzz_autounmask
```
(chroot) livecd / # mkdir -p -v /etc/portage/package.use
(chroot) livecd / # touch /etc/portage/package.use/zzz_via_autounmask
(chroot) livecd / # emerge --ask --verbose dev-vcs/git
... additional output suppressed ...
Would you like to merge these packages? [Yes/No] <press y, then Enter>
... additional output suppressed ...
```
*Write about good housekeeping and why zzz_autounmask starts with zzz_*
*Write about why we created the directory*

Tell portage about our directory.
```
(chroot) livecd / # nano -w /etc/portage/repos.conf/sakaki-tools.conf
[sakaki-tools]

# Various utility ebuilds for Gentoo on EFI
# Maintainer: sakaki (sakaki@deciban.com)

location = /usr/local/portage/sakaki-tools
sync-type = git
sync-uri = https://github.com/sakaki-/sakaki-tools.git
priority = 50
auto-sync = yes
```
*Explain what we did*

Pull in the ebuild repository:
```
(chroot) livecd / # emaint sync --repo sakaki-tools
(chroot) livecd / # mkdir -p -v /etc/portage/package.mask
(chroot) livecd / # echo '*/*::sakaki-tools' >> /etc/portage/package.mask/sakaki-tools-repo
```
*explain each command*
*talk about priority and overlays and mask, unmask, read*

Wildcard-mask everything from sakaki-tools
```
(chroot) livecd / # mkdir -p -v /etc/portage/package.mask
(chroot) livecd / # echo '*/*::sakaki-tools' >> /etc/portage/package.mask/sakaki-tools-repo
```
Unmask showem to enable output from a parallel emerge
```
(chroot) livecd / # mkdir -p -v /etc/portage/package.unmask
(chroot) livecd / # touch /etc/portage/package.unmask/zzz_via_autounmask
(chroot) livecd / # echo "app-portage/showem::sakaki-tools" >> /etc/portage/package.unmask/showem
```
All user repository ebuilds *must* specify that they are on the 'unstable' branch (since they are not yet fully tested). Our current configuration only allows fro stable (amd64) packages; we have to specify that the sakaki-tools repo is acceptable by applying the ('tilde') inside of /etc/portage/package.accept_keywords directory:
```
(chroot) livecd / # mkdir -p -v /etc/portage/package.accept_keywords
(chroot) livecd / # touch /etc/portage/package.accept_keywords/zzz_via_autounmask
(chroot) livecd / # echo "*/*::sakaki-tools ~amd64" >> /etc/portage/package.accept_keywords/sakaki-tools-repo
```
This uses a [qualified atom](some link to atoms). *explain atoms*

Install the showem tools using emerege:
```
(chroot) livecd / # emerge -av app-portage/showem
... additional output suppressed ...
Would you like to merge these packages? [Yes/No] <press y, then Enter>
... additional output suppressed ...
```
### Build the system ###
Now that we will begin building our system; we should open another virtual console. *If using ssh and screen*: press Ctrl-a then c to start a new console. *If building directly on the computer*: <Ctrl>-<Alt>-<f2>.

```
livecd ~ # chroot /mnt/gentoo /bin/bash
livecd / # source /etc/profile
livecd / # export PS1="(chroot:2) $PS1"
```
### Re-Build the system (Optional but Recommended) ###
In Gentoo, everything comes down to choice. If you choose to follow this portion of the [Gentoo Secure Cluster](http://safesecs.io/gentoo-secure-cluster), you are ensuring the integrity of your build (to a certain point). As was discussed by Ken Thompson in his 1983 Turing Award lecture, "Reflections on Trusting Trust", the use of any 'original' binary can potentially expose all 'downstream' code to a backdoor attack. This applies to our situtation by using the stage3 tarball provided by Gentoo; basically, by using the precompiled gcc binary for our system we are introducing a small amount of uncertainty to the security of our build. We did not compile the gcc binary ourselves, thus we cannot ensure that the compilier does not have some vulnerability built in that we don't know about. GCC is used to compile all of the packages on our system and these packages would then have the same vulnerability (if any) that the gcc compiler itself has. Seeing that this guide is focused on security, we recommend re-compiling your system from a stage1 tarball and building it back up to stage3; this is the focus of the current section. Seeing that Gentoo is about choice, there is no obligation to do so; and in fact, we will be providing an [ISO](some link to iso) image for you to download and use on your hosting provider as the baseline image for your kubernetes cluster. The image will be an exact replica of the outcome of this guide, if you trust that we're not lying ;). For those of you that have created your own system using *Linux from Scratch* this will seem familiar; luckily, we have Portage to do all of the dirty work for us! Let's begin ensuring our build's integrity:

```
(chroot) livecd / # cd /usr/portage/scripts
(chroot) livecd /usr/portage/scripts # ./bootstrap.sh --pretend
... additional output suppressed ...
  [[ (0/3) Locating packages ]]
* Using baselayout : >=sys-apps/baselayout-2
* Using portage    : portage
* Using os-headers : >=sys-kernel/linux-headers-4.14-r1
* Using binutils   : sys-devel/binutils
* Using gcc        : sys-devel/gcc
* Using gettext    : gettext
* Using libc       : virtual/libc
* Using texinfo    : sys-apps/texinfo
* Using zlib       : zlib
* Using ncurses    : ncurses
... addtional output suppressed ...
!!! CONFIG_PROTECT is empty
... addtional output suppressed ...
```
Exact versions and packages shown in your output may differ. The ./bootstrap.sh script is selecting the order of packages we will need to compile to create a basic toolchain for our newly built system. The Gentoo [FAQ](link to gentoo FAQ) suggests that you can edit your bootstrap.sh script after reviewing it. We will be doing this to change our libc version. The bootstrap script is choosing to build the *virtual* libc; however, in Portage, emerging a virtual package does not, by default, cause an already installed package that satisfies the *virtual* to be rebuilt. We want to re-build our glibc, so issue:
```
(chroot) livecd /usr/portage/scripts # nano -w bootstrap.sh
<Ctrl-w>
Search: should never fail <Enter>
```
Navigate to the line setting myLIBC and modify it (change && to ;):
```
[[ -z ${myBASELAYOUT} ]] && myBASELAYOUT=">=$(portageq best_version / sys-apps/baselayout)"
... additional lines suppressed ...
[[ -z ${myLIBC}       ]] ; myLIBC="$(portageq expand_virtual / virtual/libc)"
[[ -z ${myTEXINFO     ]] && myTEXINFO="sys-apps/texinfo"
... additional lines suppressed ...
```
The other two lines are a reference and **are not** modified. We have changed the conditional statement to a regular statement, forcing bootstrap.sh to recompile virtual/libc which causes sys-libs/glibc to be recompiled. This file is a part of the main Gentoo ebuild repository and your changes will be overwrittent the next time you sync. This is not a problem because we are only bootstrapping our system once.

Ensure our changes do what we want:
```
(chroot) livecd /usr/portage/scripts # ./bootstrap.sh --pretend
... additional output suppressed ... 
  [[ (0/3) Locating packages ]]
* Using baselayout : >=sys-apps/baselayout-2
* Using portage    : portage
* Using os-headers : >=sys-kernel/linux-headers-4.14-r1
* Using binutils   : sys-devel/binutils
* Using gcc        : sys-devel/gcc
* Using gettext    : gettext
* Using libc       : sys-libs/glibc:2.2
* Using texinfo    : sys-apps/texinfo
* Using zlib       : zlib
* Using ncurses    : ncurses
... addtional output suppressed ... 
!!! CONFIG_PROTECT is empty
... addtional output suppressed ... 
```
Now we should handle the fact that CONFIG_PROTECT is empty; this is telling us that our bootstrap process will *not* preserve any configuration files you may have modified if any of the packages to be installed try to overwrite them. So far, we have modified two configuration files (that will be overwritten). To remedy this, we will be using the supplied qfile utility to check if either is affected.
```
(chroot) livecd /usr/portage/scripts # qfile /etc/locale.gen /etc/conf.d/keymaps
sys-apps/openrc (/etc/conf.d/keymaps)
sys-libs/glibc (/etc/locale.gen)
```
This tells us that the package sys-libs/glibc owns the /etc/locale.gen configuration file. The bootstrap is going to emerge the glibc, so we will make a backup of the configuration file.
```
(chroot) livecd /usr/portage/scripts # cp -v /etc/local.gen{,.bak}
```
You may be thinking, "We also edited /etc/portage/make.conf why aren't we backing it up?" The reasoning behind this is that no package owns that file, meaning it will not be overwritten by executing the bootstrap.sh script. Let's begin the bootstrap:
```
(chroot) livecd /usr/portage/scripts # ./bootstrap.sh
```
Grab some coffee or tea, because this will take a while; if you wish, you can switch to your second screen (<Ctrl-<Alt>-<f2> or <Ctrl-a><n> if using screen) and watch your system being built.
```
(chroot:2) livecd / # showem
```
*Show screen shots*

Now that we have rebuilt gcc, we should check its configuration. Issue:
```
(chroot) livecd /usr/portage/scripts # gcc-config --list-profiles
```
Sometimes upgrading gcc will not work properly; the most likely cause is that the *Application Binary Interface* (ABI), libtool uses hardcoded gcc version information. **If, and only if, the output tells you the configuration is invalid, issue the following to fix it:**
```
(chroot) livecd /usr/portage/scripts # gcc-config 1
(chroot) livecd /usr/portage/scripts # env-update && source /etc/profile && export PS1="(chroot) $PS1"
(chroot) livecd /usr/portage/scripts # emerge --ask --verbose --oneshot sys-devel/libtool

... additional output suppressed ...
Would you like to merge these packages? [Yes/No] <press y, then Enter>
... additional output suppressed ...
```
For further reading check out [Upgrading GCC](https://wiki.gentoo.org/wiki/Upgrading_GCC).

Now we run ./bootstrap.sh again, to ensure that everything in the toolchain is rebuilt using the new compiler.
```
(chroot) livecd /usr/portage/scripts # ./bootstrap.sh
 * System has been bootstrapped already!
 * If you re-bootstrap the system, you must complete the entire bootstrap process
 * otherwise you will have a broken system.
 * Press enter to continue or CTRL+C to abort ..
<press Enter>
... additional output suppressed ...
```
Again you can switch between screens to watch the progress; if you do so, issue:
```
(chroot:2) livecd / # source /etc/profile && export PS1="(chroot:2) $PS1"
(chroot:2) livecd / # showem
```
After the toolchain bootstrap has completed (for the second time), we are nearly ready to rebuild all of the other binaries using it. First issue:
```
(chroot) livecd /usr/portage/scripts # gcc-config --list-profiles
(chroot) livecd /usr/portage/scripts # mv -v /etc/locale.gen{.bak,}
(chroot) livecd /usr/portage/scripts # locale-gen
```
Ensure your locale is still set to 'C'
```
(chroot) livecd /usr/portage/scripts # eselect locale show
LANG variable in profile:
  C
```
And finally:
```
(chroot) livecd /usr/portage/scripts # cd /
```
### From stage2 to stage3 ###
Our system could now be considered to be using a stage2 tarball (we have a basic toolchain without any of the really fun binaries). We will now build everything in the [@world](link to @world package set) package set. To start, create an empty 'timestamp' file that we'll use as a marker to check that all of our executables and libraries have been rebuilt.
```
(chroot) livecd / # touch /tmp/prebuild_checkpoint
(chroot) livecd / # emerge --ask --verbose --emptytree --with-bdeps=y @world
... additional output suppressed ...
Would you like to merge these packages? [Yes/No] <press y, then Enter>
... additional output suppressed ...
```
If emerge complains about kernel flags not being set correctly, ignore it for now. We will build a kernel with the correct flags shortly.

*Explain emerge parameters*
