# VPN-GW — RouterOS 6 preparation. Apply in SAFE MODE (Winbox "Safe Mode" / terminal Ctrl+X).
# Replace: 10.0.10.20 (VPN-GW server), certificate name, password.
# Prerequisite: certificate "router-api" issued by the corporate CA (SAN = router IP), imported with key
# (same commands as for RouterOS 7). The portal VERIFIES this certificate.

# --- management service for the portal (API over TLS) ---
/ip service set api-ssl disabled=no port=8729 certificate=router-api address=10.0.10.20/32
/ip service set api disabled=yes
/ip service set telnet disabled=yes
/ip service set ftp disabled=yes
/ip service set www disabled=yes

# --- dedicated least-privilege account ---
/user group add name=vpn-portal policy=read,write,api comment="VPN-GW portal"
/user add name=vpn_portal group=vpn-portal address=10.0.10.20/32 password="CHANGE-ME-32+-random-chars" comment="VPN-GW portal"

# --- L2TP/IPsec: L2TP never without IPsec, MS-CHAPv2 only ---
/interface l2tp-server server set enabled=yes use-ipsec=required authentication=mschap2

# --- firewall (same rules as RouterOS 7). DROP rules created DISABLED until go-live. ---
/ip firewall filter
add chain=input protocol=udp dst-port=500,4500 src-address-list=Allowed_VPN_Users action=accept comment="VPN: IKE allow"
add chain=input protocol=ipsec-esp src-address-list=Allowed_VPN_Users action=accept comment="VPN: ESP allow"
add chain=input protocol=udp dst-port=1701 ipsec-policy=in,ipsec action=accept comment="VPN: L2TP only inside IPsec"
add chain=input protocol=udp dst-port=500,1701,4500 action=drop comment="VPN: deny others" disabled=yes
add chain=input protocol=ipsec-esp action=drop comment="VPN: ESP deny others" disabled=yes
add chain=input protocol=tcp dst-port=8729 src-address=!10.0.10.20 action=drop comment="VPN-GW: API-SSL only from portal"
