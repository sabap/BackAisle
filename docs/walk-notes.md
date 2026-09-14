# SNMPv3 walk notes — CyberPower RMCARD205

Protocol: SNMPv3 authPriv, SHA + AES-128. MIB reference: official CPS-MIB v2.13 (`docs/CPS-MIB.mib`).

PHP `snmp3_*` often cannot build OIDs if net-snmp MIB files are missing. The poller is Python + pysnmp.

## Decode

- Runtime TimeTicks: divide by 100 then 60 for minutes.
- Voltages: ×0.1.
- ENVIROSENSOR live path is `environmentSensor2` (`1.3.6.1.4.1.3808.1.1.8`), not legacy scalars `.1.1.4.2.1.0` / `.1.1.4.3.1.0` (those may stay SNMP Null).
- `envir2TempUnit` 1=C 2=F; `envir2Temperature` and `envir2Humidity` are 1/100 of unit. Convert C→F for the UI. Do not display raw 2368 as °F.
- Contacts: status 1=normal 2=abnormal.

See `docs/metric-catalog.json` for the poll OID set.
