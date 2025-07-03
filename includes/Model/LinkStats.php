<?php
namespace GrocersList\Model;

class LinkStats {
    public int $clickedTotal;
    public int $clickedMobile;

    public function __construct(array $data) {
        $this->clickedTotal = $data['clicked_total'] ?? 0;
        $this->clickedMobile = $data['clicked_mobile'] ?? 0;
    }
}
