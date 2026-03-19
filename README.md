# IoT 환경 센서 모니터링 시스템

실험실, 사무실, 서버실, 야외 4개 위치의 온도·습도·CO₂·PM2.5 센서 데이터를
실시간으로 수집하고 웹 대시보드에 표시하는 IoT 환경 모니터링 시스템입니다.

---

## 프로젝트 개요

| 항목 | 내용 |
|------|------|
| 데이터 생성 | Python (`injector.py`) — 3초마다 4개 위치 센서 데이터 삽입 |
| 데이터 저장 | MySQL — `env_monitor` DB, `sensor_data` 테이블 |
| 웹 대시보드 | PHP (`index.php`) — Bootstrap 5 다크 테마, 3초 자동 갱신 |
| JSON API | PHP (`api.php`) — 최신 데이터 및 최근 20개 기록 반환 |
| 웹 서버 | Apache2 |

---

## 요구 사항

- **OS**: Ubuntu 20.04 / 22.04 (또는 호환 Debian 계열)
- **MySQL** 또는 **MariaDB**
- **Apache2** + **PHP 8.x** + **php-mysqli** 확장
- **Python 3.8+** + `pymysql` 패키지

### 패키지 설치 (예시)

```bash
sudo apt-get update
sudo apt-get install -y mysql-server apache2 php php-mysqli python3-pip
pip3 install pymysql
```

---

## 실행 방법

### 1단계: setup.sh 실행

프로젝트 디렉터리에서 아래 명령을 실행합니다.

```bash
cd /home/changyeon/em_linux
sudo bash setup.sh
```

`setup.sh`가 수행하는 작업:
- MySQL DB(`env_monitor`) 및 사용자(`env_user`) 생성
- `sensor_data` 테이블 생성
- PHP 파일을 `/var/www/html/env_monitor/`로 복사
- Apache2 재시작

### 2단계: injector.py 실행

새 터미널을 열고 데이터 생성기를 실행합니다.

```bash
python3 /home/changyeon/em_linux/injector.py
```

3초마다 4개 위치의 센서 데이터가 DB에 삽입됩니다.
종료하려면 `Ctrl+C`를 누르세요.

### 3단계: 대시보드 접속

웹 브라우저에서 아래 주소로 접속합니다.

```
http://localhost/env_monitor
```

- 대시보드는 **3초마다 자동 갱신**됩니다.
- JSON API: `http://localhost/env_monitor/api.php`

---

## 파일 구조

```
em_linux/
├── db.php          # DB 접속 설정
├── index.php       # 실시간 웹 대시보드
├── api.php         # JSON API 엔드포인트
├── injector.py     # 센서 데이터 생성기 (Python)
├── setup.sh        # 초기 설정 스크립트
├── README.md       # 프로젝트 설명 (이 파일)
├── process.md      # 프로젝트 문서
└── submission.txt  # 제출 정보
```

---

## 센서 측정 범위

| 위치 | 온도 | 습도 | CO₂ | PM2.5 |
|------|------|------|-----|-------|
| 실험실 | 18–25°C | 40–60% | 400–800 ppm | 5–20 µg/m³ |
| 사무실 | 22–28°C | 45–65% | 600–1200 ppm | 8–25 µg/m³ |
| 서버실 | 20–35°C | 30–50% | 400–600 ppm | 3–15 µg/m³ |
| 야외 | 5–38°C | 30–90% | 380–450 ppm | 10–80 µg/m³ |

---

## 경고 기준

| 센서 | 주의 | 위험 |
|------|------|------|
| 온도 | > 26°C | > 30°C |
| 습도 | < 30% (낮음) | > 70% (높음) |
| CO₂ | > 800 ppm | > 1000 ppm |
| PM2.5 | > 15 µg/m³ | > 35 µg/m³ |

---

## 문제 해결

**DB 연결 실패 시**: `setup.sh`를 먼저 실행했는지 확인하세요.

**데이터가 표시되지 않을 때**: `injector.py`가 실행 중인지 확인하세요.

**pymysql 없음 오류**:
```bash
pip3 install pymysql
```

**Apache PHP mysqli 오류**:
```bash
sudo apt-get install php-mysqli
sudo systemctl restart apache2
```
