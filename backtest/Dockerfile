FROM python:3.12-slim

WORKDIR /engine
COPY pyproject.toml requirements.txt ./
COPY trading_engine ./trading_engine
RUN pip install --no-cache-dir .

USER 65532:65532
ENTRYPOINT ["trading-engine"]
CMD ["worker"]
