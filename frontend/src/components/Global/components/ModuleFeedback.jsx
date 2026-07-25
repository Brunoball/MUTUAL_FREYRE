import React from "react";
import Toast from "../Toast";

const DEFAULT_DURATION = {
  success: 2800,
  error: 4200,
  warning: 4200,
  info: 3200,
  loading: null,
};

export default function ModuleFeedback({
  type = "success",
  message,
  duration,
  onClose,
}) {
  if (!message) return null;

  return (
    <Toast
      duration={duration === undefined ? DEFAULT_DURATION[type] : duration}
      key={`${type}-${message}`}
      message={message}
      onClose={onClose}
      type={type}
    />
  );
}
