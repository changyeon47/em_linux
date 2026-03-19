#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
injector.py — IoT 환경 센서 데이터 생성기
=========================================
4개 위치(실험실, 사무실, 서버실, 야외)의 환경 센서 데이터를
3초마다 MySQL DB에 삽입하는 시뮬레이터.

MySQL CLI를 subprocess로 호출하므로 별도 Python 패키지 불필요.

사용법:
    python3 injector.py

종료:
    Ctrl+C
"""

import subprocess
import random
import time
import math
from datetime import datetime

# ────────────────────────────────────────────
# DB 접속 설정
# ────────────────────────────────────────────
DB_HOST = 'localhost'
DB_USER = 'library_user'
DB_PASS = 'library_pass123'
DB_NAME = 'library_db'

MYSQL_CMD = [
    'mysql',
    f'-h{DB_HOST}',
    f'-u{DB_USER}',
    f'-p{DB_PASS}',
    DB_NAME,
    '--silent',
]

# ────────────────────────────────────────────
# 위치별 센서 기준값 및 범위 정의
# ────────────────────────────────────────────
LOCATION_PROFILES = {
    '실험실': {
        'temp_base':  21.5,   # 기준 온도 (°C)
        'temp_range':  3.5,   # 변동 폭
        'hum_base':   50.0,   # 기준 습도 (%)
        'hum_range':  10.0,
        'co2_base':   600,    # 기준 CO₂ (ppm)
        'co2_range':  200,
        'pm25_base':  12.5,   # 기준 PM2.5 (µg/m³)
        'pm25_range':  7.5,
        'noise':       0.8,   # 랜덤 노이즈 강도
    },
    '사무실': {
        'temp_base':  25.0,
        'temp_range':  3.0,
        'hum_base':   55.0,
        'hum_range':  10.0,
        'co2_base':   900,
        'co2_range':  300,
        'pm25_base':  16.5,
        'pm25_range':  8.5,
        'noise':       1.2,
    },
    '서버실': {
        'temp_base':  27.5,   # 서버 발열로 온도 높음
        'temp_range':  7.5,
        'hum_base':   40.0,   # 제습 유지
        'hum_range':  10.0,
        'co2_base':   500,    # 사람이 적어 CO₂ 낮음
        'co2_range':  100,
        'pm25_base':   9.0,
        'pm25_range':  6.0,
        'noise':       1.5,   # 장비 부하 변동
    },
    '야외': {
        'temp_base':  21.5,   # 계절/시간대 변화 큼
        'temp_range': 16.5,
        'hum_base':   60.0,
        'hum_range':  30.0,
        'co2_base':   415,    # 대기 중 CO₂
        'co2_range':   35,
        'pm25_base':  45.0,   # 외부 오염 영향
        'pm25_range': 35.0,
        'noise':       3.0,   # 바람 등 외부 요인
    },
}

# 최대 보관 레코드 수
MAX_ROWS = 1000


def now() -> str:
    return datetime.now().strftime('%Y-%m-%d %H:%M:%S')


def run_sql(sql: str) -> bool:
    """mysql CLI를 통해 SQL을 실행한다. 성공 시 True 반환."""
    try:
        result = subprocess.run(
            MYSQL_CMD,
            input=sql,
            capture_output=True,
            text=True,
            timeout=10
        )
        if result.returncode != 0:
            # 비밀번호 경고는 stderr에 나타날 수 있으므로 실제 오류만 필터링
            err = result.stderr.strip()
            real_err = '\n'.join(
                line for line in err.splitlines()
                if 'Using a password' not in line
            )
            if real_err:
                print(f"  [SQL 오류] {real_err[:200]}")
                return False
        return True
    except subprocess.TimeoutExpired:
        print(f"  [오류] SQL 타임아웃 (10초 초과)")
        return False
    except FileNotFoundError:
        print(f"  [오류] mysql 명령어를 찾을 수 없습니다. MySQL Client가 설치되어 있는지 확인하세요.")
        return False


def check_connection() -> bool:
    """DB 연결 테스트"""
    return run_sql("SELECT 1;")


def generate_value(base: float, wave_range: float, noise_scale: float,
                   t: float, freq: float = 1.0) -> float:
    """
    사인파(1시간 주기) + 가우시안 노이즈로 현실적인 센서값을 생성한다.
    base       : 기준값
    wave_range : 사인파 변동 폭 (±)
    noise_scale: 가우시안 노이즈 표준편차
    t          : 경과 시간(초)
    freq       : 사인파 주파수 배율
    """
    wave  = wave_range * math.sin(2 * math.pi * freq * t / 3600.0)
    noise = random.gauss(0, noise_scale)
    return base + wave + noise


def generate_sensor_data(location: str, t: float) -> dict:
    """위치와 경과시간을 받아 현실적인 센서 측정값 딕셔너리를 반환한다."""
    p = LOCATION_PROFILES[location]
    n = p['noise']

    # 온도: 1시간 주기 사인파 + 노이즈
    temp = generate_value(p['temp_base'], p['temp_range'], n * 0.5, t, freq=1.0)
    temp = round(max(-10.0, min(60.0, temp)), 2)

    # 습도: 온도와 약간 역상관 (온도 높으면 상대 습도 낮아지는 경향 반영)
    hum_adjust = -(temp - p['temp_base']) * 0.3
    hum = generate_value(p['hum_base'] + hum_adjust, p['hum_range'], n * 0.8, t, freq=0.7)
    hum = round(max(0.0, min(100.0, hum)), 2)

    # CO₂: 사무실/실험실은 재실 패턴 반영 (낮 시간대 높음)
    hour_of_day = (t / 3600.0) % 24
    occupancy_wave = 0.0
    if location in ('사무실', '실험실'):
        if 9 <= hour_of_day <= 18:
            occupancy_wave = p['co2_range'] * 0.4 * math.sin(
                math.pi * (hour_of_day - 9) / 9
            )
    co2 = generate_value(p['co2_base'] + occupancy_wave, p['co2_range'], n * 15, t, freq=1.3)
    co2 = int(max(300, min(5000, co2)))

    # PM2.5: 야외는 출퇴근 시간대 상승 시뮬레이션
    pm25_extra = 0.0
    if location == '야외':
        if 7 <= hour_of_day <= 9 or 17 <= hour_of_day <= 19:
            pm25_extra = random.uniform(5, 20)
    pm25 = generate_value(p['pm25_base'] + pm25_extra, p['pm25_range'], n * 1.2, t, freq=0.5)
    pm25 = round(max(0.0, min(500.0, pm25)), 2)

    return {
        'location':    location,
        'temperature': temp,
        'humidity':    hum,
        'co2':         co2,
        'pm25':        pm25,
    }


def insert_reading(data: dict) -> bool:
    """센서 데이터를 DB에 삽입한다."""
    sql = (
        f"INSERT INTO sensor_data (location, temperature, humidity, co2, pm25) "
        f"VALUES ('{data['location']}', {data['temperature']}, "
        f"{data['humidity']}, {data['co2']}, {data['pm25']});"
    )
    return run_sql(sql)


def cleanup_old_records():
    """전체 레코드가 MAX_ROWS를 초과하면 오래된 것을 삭제한다."""
    sql = f"""
        DELETE FROM sensor_data
        WHERE id NOT IN (
            SELECT id FROM (
                SELECT id FROM sensor_data
                ORDER BY recorded_at DESC
                LIMIT {MAX_ROWS}
            ) AS keep_rows
        );
    """
    run_sql(sql)


def status_line(location: str, data: dict) -> str:
    """위치별 데이터를 한 줄 요약 문자열로 반환한다."""
    return (
        f"  {location:<5} | "
        f"온도:{data['temperature']:6.1f}C  "
        f"습도:{data['humidity']:5.1f}%  "
        f"CO2:{data['co2']:4d}ppm  "
        f"PM2.5:{data['pm25']:5.1f}ug/m3"
    )


def main():
    print("=" * 62)
    print("  IoT 환경 센서 데이터 인젝터 시작")
    print(f"  대상 위치: {', '.join(LOCATION_PROFILES.keys())}")
    print(f"  삽입 주기: 3초 | 최대 보관: {MAX_ROWS}개")
    print(f"  DB: {DB_USER}@{DB_HOST}/{DB_NAME}")
    print("  종료하려면 Ctrl+C 를 누르세요")
    print("=" * 62)

    # 초기 연결 확인 (최대 3회 재시도)
    for attempt in range(1, 4):
        if check_connection():
            print(f"[{now()}] DB 연결 성공")
            break
        print(f"[{now()}] DB 연결 실패 (시도 {attempt}/3) — 5초 후 재시도...")
        time.sleep(5)
    else:
        print(f"[{now()}] DB에 연결할 수 없습니다. 종료합니다.")
        return

    cycle = 0
    start_time = time.time()

    try:
        while True:
            cycle += 1
            t = time.time() - start_time  # 경과 시간(초) — 사인파 인덱스로 사용

            print(f"\n[{now()}] --- Cycle #{cycle} ---")

            for location in LOCATION_PROFILES:
                data = generate_sensor_data(location, t)
                ok = insert_reading(data)
                status = "OK" if ok else "FAIL"
                print(f"{status_line(location, data)}  [{status}]")

            # 오래된 레코드 정리 (10사이클마다)
            if cycle % 10 == 0:
                cleanup_old_records()
                print(f"  [정리] 오래된 레코드 정리 완료 (상위 {MAX_ROWS}개 유지)")

            time.sleep(3)

    except KeyboardInterrupt:
        print(f"\n\n[{now()}] 인젝터 종료 (Ctrl+C)")
        print(f"  총 {cycle}사이클, 약 {cycle * len(LOCATION_PROFILES)}개 레코드 삽입")


if __name__ == '__main__':
    main()
