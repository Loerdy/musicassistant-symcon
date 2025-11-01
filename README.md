# Music Assistant for IP-Symcon

Modules:
- MusicAssistantBridge: Creates/configures the WebSocket client and auto-discovers players.
- MusicAssistantPlayer: One instance per player with State/Volume/GroupVolume and Play/Pause/Next/Previous.

## Install
1) In IP-Symcon: Kern-Instanzen → Module Control → Neues Modul → Git-URL:
   https://github.com/Loerdy/musicassistant-symcon.git
2) Instanz "MusicAssistant Bridge" anlegen.
3) Properties prüfen:
   - Host: 192.168.29.17
   - Port: 8095
   - Path: /ws
   - AutoCreateIO: On
   - PlayersRootID: leer lassen (Bridge legt "Music Assistant Players" automatisch an)
4) Übernehmen. Bridge erstellt WebSocket-Client und RegisterVariable, empfängt Events und legt Player-Instanzen automatisch an.

## Commands used
- players/cmd/play_pause
- players/cmd/next
- players/cmd/previous
- players/cmd/volume_set (0.0–1.0)
- players/cmd/group_volume (0–100)

## Development
```
git init
git remote add origin https://github.com/Loerdy/musicassistant-symcon.git
git add .
git commit -m "Initial commit: MusicAssistant Bridge & Player"
git branch -M main
git push -u origin main
```
