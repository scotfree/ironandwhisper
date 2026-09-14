"""Adam, lifted from the update block of karpathy.py's `train()`.

Same moments, same bias correction, same linear decay. It is separated out
only because both objectives here — cloning and policy gradient — need it, and
because gradients arrive from worker processes as a flat list rather than
sitting on the Value objects.
"""

from __future__ import annotations


class Adam:
    def __init__(self, size: int, lr: float = 0.01, beta1: float = 0.85,
                 beta2: float = 0.99, eps: float = 1e-8):
        self.lr, self.beta1, self.beta2, self.eps = lr, beta1, beta2, eps
        self.m = [0.0] * size
        self.v = [0.0] * size
        self.t = 0

    def step(self, weights: list[float], grads: list[float],
             lr_scale: float = 1.0) -> list[float]:
        """Return updated weights. `lr_scale` carries the decay schedule."""
        self.t += 1
        beta1, beta2, eps = self.beta1, self.beta2, self.eps
        lr = self.lr * lr_scale
        out = []
        for i, (w, g) in enumerate(zip(weights, grads)):
            self.m[i] = beta1 * self.m[i] + (1 - beta1) * g
            self.v[i] = beta2 * self.v[i] + (1 - beta2) * g * g
            m_hat = self.m[i] / (1 - beta1 ** self.t)
            v_hat = self.v[i] / (1 - beta2 ** self.t)
            out.append(w - lr * m_hat / (v_hat ** 0.5 + eps))
        return out
