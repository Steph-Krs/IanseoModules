<?php
/**
 * A made-up draw, so that the preparation, control, public and commentators' screens of
 * TIRAGE have content to build (tests/dom/TIRAGE.js takes the latest draw listed).
 */
$benchToken   = bin2hex(random_bytes(16));
$benchSpeaker = bin2hex(random_bytes(16));
safe_w_sql("INSERT INTO DrawShows (DwTitle, DwSubtitle, DwToken, DwSpeakerToken)
            VALUES ('Tirage fictif', 'Banc de test', '$benchToken', '$benchSpeaker')");
$benchShow = safe_w_last_id();

safe_w_sql("INSERT INTO DrawCategories (DcShow, DcOrder, DcName, DcType) VALUES ($benchShow, 1, 'Categorie fictive', 'teams')");
$benchCategory = safe_w_last_id();

for ($i = 1; $i <= 4; $i++) {
    safe_w_sql("INSERT INTO DrawTeams (DtCategory, DtName, DtDetail, DtOrder) VALUES ($benchCategory, 'Equipe fictive $i', 'Club fictif', $i)");
}
