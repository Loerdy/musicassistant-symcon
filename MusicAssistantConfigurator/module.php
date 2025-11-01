<?php
class MusicAssistantConfigurator extends IPSModule
{
    // GUIDs of other modules
    private const BRIDGE_MODULE_GUID = '{C3E33C98-6B1B-4C63-9C7E-9E16A35C0D3F}';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('BridgeInstanceID', 0);
        $this->RegisterPropertyInteger('PlayersRootID', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
    }

    public function GetConfigurationForm()
    {
        $bridgeId = $this->ReadPropertyInteger('BridgeInstanceID');
        $playersRoot = $this->ReadPropertyInteger('PlayersRootID');

        $bridgeOk = ($bridgeId > 0 && @IPS_GetInstance($bridgeId)['ModuleInfo']['ModuleID'] ?? '' ) === self::BRIDGE_MODULE_GUID;
        $players = [];
        if ($bridgeOk) {
            // Hole die gecachten Player aus der Bridge
            $players = @MABR_GetPlayers($bridgeId);
            if (!is_array($players)) $players = [];
        }
        // Ermitteln bereits angelegter Player-Instanzen (nach PlayerID)
        $existing = [];
        if ($playersRoot > 0) {
            foreach (IPS_GetChildrenIDs($playersRoot) as $child) {
                $inst = @IPS_GetInstance($child);
                if (!$inst) continue;
                // Player-Instanz erkennen über Properties
                $pidProp = @IPS_GetProperty($child, 'PlayerID');
                if ($pidProp) {
                    $existing[$pidProp] = $child;
                }
            }
        }

        $values = [];
        foreach ($players as $p) {
            $pid = $p['player_id'] ?? '';
            $name = $p['display_name'] ?? $pid;
            $has = array_key_exists($pid, $existing);
            $values[] = [
                'PlayerID' => $pid,
                'Name'     => $name,
                'Exists'   => $has,
                'InstanceID' => $has ? $existing[$pid] : 0,
                'create'   => ['caption' => $has ? 'Vorhanden' : 'Erstellen', 'onClick' => $has ? '' : 'MACF_CreateFromConfig($id, "'.$pid.'");']
            ];
        }

        $form = [
            'elements' => [
                ['type' => 'SelectInstance', 'name' => 'BridgeInstanceID', 'caption' => 'Bridge', 'validModules' => [self::BRIDGE_MODULE_GUID], 'value' => $bridgeId],
                ['type' => 'SelectCategory', 'name' => 'PlayersRootID', 'caption' => 'Players Root', 'value' => $playersRoot],
                ['type' => 'List', 'name' => 'Players', 'caption' => 'Verfügbare Player', 'rowCount' => 10, 'add' => false, 'delete' => false,
                    'columns' => [
                        ['label' => 'Name', 'name' => 'Name', 'width' => 'auto'],
                        ['label' => 'PlayerID', 'name' => 'PlayerID', 'width' => '250px'],
                        ['label' => 'Instanz', 'name' => 'InstanceID', 'width' => '120px'],
                        ['label' => 'Vorhanden', 'name' => 'Exists', 'width' => '100px']
                    ],
                    'values' => $values
                ]
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Liste aktualisieren', 'onClick' => 'echo MACF_Refresh($id);']
            ]
        ];
        return json_encode($form);
    }

    public function Refresh()
    {
        // Nur Formular neu rendern lassen
        return 'OK';
    }

    public function CreateFromConfig(string $playerId)
    {
        $bridgeId = $this->ReadPropertyInteger('BridgeInstanceID');
        if ($bridgeId <= 0) {
            echo 'Bridge nicht gesetzt';
            return 0;
        }
        $iid = @MABR_CreatePlayerInstance($bridgeId, $playerId);
        if ($iid > 0) {
            echo 'Instanz erstellt: ' . $iid;
        } else {
            echo 'Fehlgeschlagen oder bereits vorhanden';
        }
        return $iid;
    }
}
