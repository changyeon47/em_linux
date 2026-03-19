#!/bin/bash
# ============================================================
# setup.sh — IoT 환경 모니터링 시스템 초기 설정 스크립트
# ============================================================
# 실행 방법: bash setup.sh
# ※ MySQL DB 생성 단계는 sudo 권한이 필요합니다.
# ============================================================

set -e

# ── 색상 코드 ──
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

info()    { echo -e "${CYAN}[INFO]${NC}  $*"; }
success() { echo -e "${GREEN}[OK]${NC}    $*"; }
warn()    { echo -e "${YELLOW}[WARN]${NC}  $*"; }
error()   { echo -e "${RED}[ERROR]${NC} $*"; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

echo ""
echo -e "${BOLD}============================================================${NC}"
echo -e "${BOLD}  IoT 환경 센서 모니터링 시스템 — 초기 설정${NC}"
echo -e "${BOLD}============================================================${NC}"
echo ""
info "프로젝트 디렉터리: $SCRIPT_DIR"

# ── MySQL 서비스 확인 ──
info "MySQL 서비스 상태 확인..."
if systemctl is-active --quiet mysql 2>/dev/null || \
   systemctl is-active --quiet mysqld 2>/dev/null || \
   systemctl is-active --quiet mariadb 2>/dev/null; then
    success "MySQL 서비스 실행 중"
else
    warn "MySQL이 실행 중이지 않습니다."
    if [ "$EUID" -eq 0 ]; then
        systemctl start mysql 2>/dev/null || systemctl start mysqld 2>/dev/null || true
    fi
fi

# ── DB / 사용자 / 테이블 생성 ──
info "데이터베이스 및 테이블 생성 중..."

# root 권한 있으면 새 DB/유저 생성, 없으면 기존 library_db 사용
if [ "$EUID" -eq 0 ] && mysql -u root -e "SELECT 1;" 2>/dev/null; then
    mysql -u root <<'SQL'
CREATE DATABASE IF NOT EXISTS env_monitor
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER IF NOT EXISTS 'env_user'@'localhost' IDENTIFIED BY 'env_pass123';
GRANT ALL PRIVILEGES ON env_monitor.* TO 'env_user'@'localhost';
FLUSH PRIVILEGES;

USE env_monitor;
CREATE TABLE IF NOT EXISTS sensor_data (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  location    VARCHAR(50)    NOT NULL,
  temperature DECIMAL(5,2)   NOT NULL,
  humidity    DECIMAL(5,2)   NOT NULL,
  co2         INT            NOT NULL,
  pm25        DECIMAL(6,2)   NOT NULL,
  recorded_at TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_location    (location),
  INDEX idx_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
    success "DB(env_monitor), 사용자(env_user), 테이블(sensor_data) 생성 완료"
else
    # root 없으면 기존 library_user / library_db 사용
    warn "root 권한 없음 → 기존 library_db 사용 (library_user)"
    mysql -u library_user -plibrary_pass123 library_db <<'SQL' 2>/dev/null
CREATE TABLE IF NOT EXISTS sensor_data (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  location    VARCHAR(50)    NOT NULL,
  temperature DECIMAL(5,2)   NOT NULL,
  humidity    DECIMAL(5,2)   NOT NULL,
  co2         INT            NOT NULL,
  pm25        DECIMAL(6,2)   NOT NULL,
  recorded_at TIMESTAMP      DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_location    (location),
  INDEX idx_recorded_at (recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
    success "테이블(sensor_data) 생성 완료 (library_db)"
fi

# ── Apache 웹 배포 (root인 경우에만) ──
if [ "$EUID" -eq 0 ]; then
    WEB_DIR="/var/www/html/env_monitor"
    info "웹 디렉터리 생성: $WEB_DIR"
    mkdir -p "$WEB_DIR"
    for f in db.php index.php api.php; do
        [ -f "$SCRIPT_DIR/$f" ] && cp "$SCRIPT_DIR/$f" "$WEB_DIR/$f"
    done
    chown -R www-data:www-data "$WEB_DIR"
    chmod -R 755 "$WEB_DIR"
    systemctl restart apache2 2>/dev/null || true
    success "Apache 배포 완료 → http://localhost/env_monitor"
else
    info "Apache 배포는 root 권한 필요 — PHP 내장 서버를 사용합니다."
    # PHP 내장 서버가 이미 실행 중인지 확인
    if pgrep -f "php -S localhost:8080" > /dev/null 2>&1; then
        warn "PHP 서버가 이미 포트 8080에서 실행 중입니다."
    else
        info "PHP 내장 개발 서버 시작 중 (포트 8080)..."
        cd "$SCRIPT_DIR"
        php -S localhost:8080 > /tmp/php_env_server.log 2>&1 &
        PHP_PID=$!
        sleep 1
        if kill -0 "$PHP_PID" 2>/dev/null; then
            success "PHP 서버 시작됨 (PID: $PHP_PID) → http://localhost:8080"
        else
            warn "PHP 서버 시작 실패. 수동으로 실행하세요:"
            warn "  cd $SCRIPT_DIR && php -S localhost:8080"
        fi
    fi
fi

# ── 완료 메시지 ──
echo ""
echo -e "${BOLD}============================================================${NC}"
echo -e "${GREEN}${BOLD}  설정 완료!${NC}"
echo -e "${BOLD}============================================================${NC}"
echo ""
if [ "$EUID" -eq 0 ]; then
    echo -e "  ${BOLD}대시보드 URL:${NC}  ${CYAN}http://localhost/env_monitor${NC}"
    echo -e "  ${BOLD}API URL:${NC}       ${CYAN}http://localhost/env_monitor/api.php${NC}"
else
    echo -e "  ${BOLD}대시보드 URL:${NC}  ${CYAN}http://localhost:8080${NC}"
    echo -e "  ${BOLD}API URL:${NC}       ${CYAN}http://localhost:8080/api.php${NC}"
fi
echo ""
echo -e "  ${BOLD}데이터 주입 실행:${NC}"
echo -e "    ${YELLOW}python3 $SCRIPT_DIR/injector.py${NC}"
echo ""
echo -e "  injector.py 실행 후 브라우저에서 대시보드를 확인하세요."
echo -e "  (3초마다 자동으로 새 데이터가 표시됩니다)"
echo -e "${BOLD}============================================================${NC}"
echo ""
