# VPN-GW — router access over SSH (RouterOS 6 and 7). Apply in SAFE MODE.
# Use this INSTEAD of the "management service" and "dedicated account" parts of
# routeros6.rsc / routeros7.rsc when the router's protocol in the admin panel is SSH.
# The L2TP/IPsec and firewall parts of those scripts still apply.
# Replace: 10.0.10.20 (VPN-GW server), 10.0.50.0/24 (admin network), password.

# --- SSH service: only from the portal and the admin network, strong crypto ---
/ip service set ssh disabled=no port=22 address=10.0.10.20/32,10.0.50.0/24
/ip ssh set strong-crypto=yes
/ip service set telnet disabled=yes
/ip service set ftp disabled=yes

# --- dedicated least-privilege account ---
/user group add name=vpn-portal policy=ssh,read,write comment="VPN-GW portal (SSH)"
/user add name=vpn_portal group=vpn-portal address=10.0.10.20/32 password="CHANGE-ME-32+-random-chars" comment="VPN-GW portal"

# --- optional: key login instead of a password -------------------------------------
# On the VPN-GW server:   ssh-keygen -t ed25519 -N "" -C vpn-gw -f vpn-gw-mikrotik
#   RouterOS 6 accepts only RSA user keys:  ssh-keygen -t rsa -b 4096 -m PEM -N "" -C vpn-gw -f vpn-gw-mikrotik
# Copy vpn-gw-mikrotik.pub to the router (Winbox Files / scp), then:
# /user ssh-keys import public-key-file=vpn-gw-mikrotik.pub user=vpn_portal
# Paste the PRIVATE key (vpn-gw-mikrotik) into the admin panel: MikroTik -> «SSH: приватний ключ»,
# then delete both files from the server and the router's Files.

# --- firewall: SSH only from the portal and admins ---
/ip firewall filter
add chain=input protocol=tcp dst-port=22 src-address=10.0.10.20 action=accept comment="VPN-GW: SSH from portal"
add chain=input protocol=tcp dst-port=22 src-address=10.0.50.0/24 action=accept comment="VPN-GW: SSH from admins"
add chain=input protocol=tcp dst-port=22 action=drop comment="VPN-GW: SSH deny others"

# --- confirm the host key -----------------------------------------------------------
# Admin panel -> MikroTik -> «Перевірити MikroTik» saves the router's SSH host key.
# Compare it from an admin PC:  ssh-keyscan -p 22 <router> | ssh-keygen -lf -
