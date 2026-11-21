# Kubernetes

示例清单，不是 Helm，也不是 Operator。镜像是本仓库的 `runtime` 目标，uid 10001。探针打在 admin 端口，不打业务端口。

| 文件 | 作用 |
|------|------|
| `deployment.yaml` | 前台 `serverd`。liveness `/healthz`，readiness `/readyz`，都走名为 `admin` 的 9100。`terminationGracePeriodSeconds: 10`，盖住默认 5 秒排空。Prometheus 注解可选，指向 9100 的 `/metrics`。 |
| `service.yaml` | 只发布 9000。admin 不进 Service。 |
| `networkpolicy.yaml` | 可选。放行 9000 和 9100，这样探针和 scrape 还进得来。 |

先在本机打出镜像，再载进 kind 或 minikube：

```sh
docker build --target runtime -t phpserver:runtime .
kind load docker-image phpserver:runtime
kubectl apply -f deploy/k8s/deployment.yaml -f deploy/k8s/service.yaml
kubectl rollout status deployment/phpserver
kubectl port-forward svc/phpserver 9000:9000
curl -sS http://127.0.0.1:9000/
```

`curl` 的 body 是 `hello`。NetworkPolicy 要单独 apply。

滚动更新时旧 Pod 要等新 Pod 的 `/readyz` 成功才退。更新期间可以一直打业务口：

```sh
while true; do curl -fsS http://127.0.0.1:9000/ >/dev/null || echo fail; done
```

`SIGTERM` 一进来 `/readyz` 就是 503，kubelet 不会再把新流量送进来。grace 短于 10 秒时，kubelet 可能在排空结束前杀掉容器。
