# VPN-GW — RouterOS 7 preparation. Apply in SAFE MODE (Winbox "Safe Mode" / terminal Ctrl+X).
# Replace: 10.0.10.20 (VPN-GW server), certificate name, password.
# Prerequisite: certificate "router-api" issued by the corporate CA (SAN = router IP), imported with key:
#   /certificate import file-name=router-api.crt passphrase=""
#   /certificate import file-name=router-api.key passphrase="<key password>"
#   /certificate set [find common-name=<CN>] name=router-api

# --- management service for the portal (REST API over HTTPS) ---
/ip service set www-ssl disabled=no port=8443 certificate=router-api address=10.0.10.20/32
/ip service set www disabled=yes
/ip service set telnet disabled=yes
/ip service set ftp disabled=yes
/ip service set api disabled=yes

# --- dedicated least-privilege account ---
# "rest-api" policy: check it exists in your RouterOS 7 build (/user group print detail)
/user group add name=vpn-portal policy=read,write,rest-api comment="VPN-GW portal"
/user add name=vpn_portal group=vpn-portal address=10.0.10.20/32 password="CHANGE-ME-32+-random-chars" comment="VPN-GW portal"

# --- L2TP/IPsec: L2TP never without IPsec, MS-CHAPv2 only ---
/interface l2tp-server server set enabled=yes use-ipsec=required authentication=mschap2

# --- firewall: allow VPN only from Allowed_VPN_Users ---
# Place these AFTER "accept established,related" and BEFORE any general drop in chain=input.
# The two DROP rules are created DISABLED: enable them only after the pilot (see INSTALL_GUIDE, stage 11).
/ip firewall filter
add chain=input protocol=udp dst-port=500,4500 src-address-list=Allowed_VPN_Users action=accept comment="VPN: IKE allow"
add chain=input protocol=ipsec-esp src-address-list=Allowed_VPN_Users action=accept comment="VPN: ESP allow"
add chain=input protocol=udp dst-port=1701 ipsec-policy=in,ipsec action=accept comment="VPN: L2TP only inside IPsec"
add chain=input protocol=udp dst-port=500,1701,4500 action=drop comment="VPN: deny others" disabled=yes
add chain=input protocol=ipsec-esp action=drop comment="VPN: ESP deny others" disabled=yes
add chain=input protocol=tcp dst-port=8443 src-address=!10.0.10.20 action=drop comment="VPN-GW: REST API only from portal"

# --- go-live (after pilot), in Safe Mode ---
# /ip firewall filter enable [find comment="VPN: deny others"]
# /ip firewall filter enable [find comment="VPN: ESP deny others"]
# --- rollback ---
# /ip firewall filter disable [find comment="VPN: deny others"]
# /ip firewall filter disable [find comment="VPN: ESP deny others"]
