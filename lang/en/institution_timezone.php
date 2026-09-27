<?php

return [
    'label' => 'Institution Time Zone',
    'help' => 'Used to display dates and define periods, reports and scheduled tasks. Data remains stored internally in UTC.',
    'warning' => 'Changing the time zone during Pilot, Phase I or Phase II may change research period boundaries and is a Measurement Configuration change. Record a new measurement phase and review baseline compatibility; approved references and historical results are not modified.',
    'current' => 'Current local time: :time',
    'scheduler' => 'Applies on the next scheduler cycle (normally within a minute) without restart. Tasks already started retain their periods; a clock change can skip or repeat a daily due time, so change outside job times and record the measurement-phase boundary.',
    'invalid' => 'Choose a valid IANA time zone.',
];
