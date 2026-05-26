<?php
/**
 * carparts006163 — 앗세이 주문내역 (parts_cate_assy_order_list)
 * 부모: carparts006161 앗세이 관리 (child FK = midx).
 * carparts006162 의 '주문실행' 으로 생성된 주문 내역을 표시하는 리스트 전용 화면.
 *
 * 리스트 전용: +등록/간편추가 없음, 행 클릭 시 조회/수정폼 진입 없음.
 *   (이전엔 무관한 로직이 들어있었으나 이 메뉴와 맞지 않아 제거함)
 */

/** 리스트 전용 — +등록/간편추가 숨김 + 조회/수정폼 진입 차단 */
function pageLoad() {
    $GLOBALS['_onlyList'] = true;
}
