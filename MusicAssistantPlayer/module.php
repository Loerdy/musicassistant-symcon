<?php
class MusicAssistantPlayer extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('PlayerID', '');
        $this->RegisterPropertyString('DisplayName', '');
        $this->RegisterPropertyInteger('WebSocketInstanceID', 0);

        $this->RegisterVariableString('State', 'State');
        $this->RegisterVariableFloat('Volume', 'Volume');
        $this->RegisterVariableInteger('GroupVolume', 'GroupVolume');
        $this->RegisterVariableBoolean('PlayPause', 'PlayPause');
        $this->RegisterVariableBoolean('Next', 'Next');
        $this->RegisterVariableBoolean('Previous', 'Previous');

        if (!IPS_VariableProfileExists('MA.Volume01')) {
            IPS_CreateVariableProfile('MA.Volume01', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('MA.Volume01', 2);
            IPS_SetVariableProfileValues('MA.Volume01', 0.00, 1.00, 0.01);
            IPS_SetVariableProfileIcon('MA.Volume01', 'Intensity');
        }
        if (!IPS_VariableProfileExists('MA.Button.PlayPause')) {
            IPS_CreateVariableProfile('MA.Button.PlayPause', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileIcon('MA.Button.PlayPause', 'PlayerPlay');
            IPS_SetVariableProfileAssociation('MA.Button.PlayPause', false, 'Play/Pause', '', -1);
        }
        if (!IPS_VariableProfileExists('MA.Button.Next')) {
            IPS_CreateVariableProfile('MA.Button.Next', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileIcon('MA.Button.Next', 'Next');
            IPS_SetVariableProfileAssociation('MA.Button.Next', false, 'Next', '', -1);
        }
        if (!IPS_VariableProfileExists('MA.Button.Previous')) {
            IPS_CreateVariableProfile('MA.Button.Previous', VARIABLETYPE_BOOLEAN);
            IPS_SetVariableProfileIcon('MA.Button.Previous', 'Previous');
            IPS_SetVariableProfileAssociation('MA.Button.Previous', false, 'Previous', '', -1);
        }

        IPS_SetVariableCustomProfile($this->GetIDForIdent('Volume'), 'MA.Volume01');
        IPS_SetVariableCustomProfile($this->GetIDForIdent('PlayPause'), 'MA.Button.PlayPause');
        IPS_SetVariableCustomProfile($this->GetIDForIdent('Next'), 'MA.Button.Next');
        IPS_SetVariableCustomProfile($this->GetIDForIdent('Previous'), 'MA.Button.Previous');
        IPS_SetVariableCustomProfile($this->GetIDForIdent('GroupVolume'), '~Intensity.100');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->EnableAction('PlayPause');
        $this->EnableAction('Next');
        $this->EnableAction('Previous');
        $this->EnableAction('Volume');
        $this->EnableAction('GroupVolume');

        $dn = $this->ReadPropertyString('DisplayName');
        if ($dn !== '') {
            @IPS_SetName($this->InstanceID, 'MA - ' . $dn);
        }
    }

    public function RequestAction($Ident, $Value)
    {
        $pid = $this->ReadPropertyString('PlayerID');
        switch ($Ident) {
            case 'PlayPause':
                $this->SendCmd('players/cmd/play_pause', ['player_id' => $pid]);
                SetValueBoolean($this->GetIDForIdent('PlayPause'), false);
                break;
            case 'Next':
                $this->SendCmd('players/cmd/next', ['player_id' => $pid]);
                SetValueBoolean($this->GetIDForIdent('Next'), false);
                break;
            case 'Previous':
                $this->SendCmd('players/cmd/previous', ['player_id' => $pid]);
                SetValueBoolean($this->GetIDForIdent('Previous'), false);
                break;
            case 'Volume':
                $val = max(0.0, min(1.0, floatval($Value)));
                SetValueFloat($this->GetIDForIdent('Volume'), $val);
                $this->SendCmd('players/cmd/volume_set', [
                    'player_id'    => $pid,
                    'volume_level' => $val
                ]);
                break;
            case 'GroupVolume':
                $ival = max(0, min(100, intval($Value)));
                SetValueInteger($this->GetIDForIdent('GroupVolume'), $ival);
                $this->SendCmd('players/cmd/group_volume', [
                    'player_id'    => $pid,
                    'volume_level' => $ival
                ]);
                break;
        }
    }

    public function UpdateFromEvent(array $data)
    {
        if (array_key_exists('state', $data)) {
            SetValueString($this->GetIDForIdent('State'), strval($data['state']));
        }
        if (array_key_exists('volume_level', $data)) {
            SetValueFloat($this->GetIDForIdent('Volume'), floatval($data['volume_level']));
        }
        if (array_key_exists('group_volume', $data)) {
            SetValueInteger($this->GetIDForIdent('GroupVolume'), intval($data['group_volume']));
        }
    }

    private function SendCmd(string $cmd, array $args): void
    {
        $io = $this->ReadPropertyInteger('WebSocketInstanceID');
        if ($io <= 0) {
            $this->SendDebug('ERROR', 'WebSocketInstanceID not set', 0);
            return;
        }
        static $mid = 1000;
        $mid++;
        $payload = [
            'command'    => $cmd,
            'message_id' => $mid,
            'args'       => $args
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $this->SendDebug('SEND', $json, 0);
        WSC_SendMessage($io, $json);
    }
}
