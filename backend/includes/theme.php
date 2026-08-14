<?php
function cpmsThemeStyleTag(): string
{
    return "<style>:root{--cpms-primary:" .
        cpmsPrimaryColor() .
        ";--cpms-secondary:" .
        cpmsSecondaryColor() .
        ";}</style>";
}
