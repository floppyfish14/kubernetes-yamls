#!/bin/bash
# Create a secret in cert-manager namespace based from file.
# Go to https://knative.dev/docs/serving/using-cert-manager-on-gcp/ for tutorial

kubectl create secret --namespace cert-manager generic cloud-dns-key --from-file=key.json=$KEY_DIRECTORY/gclouddns.key.json
