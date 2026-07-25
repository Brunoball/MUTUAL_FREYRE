import React from "react";
import ModuleScaffold from "../_shared/ModuleScaffold";
import { MODULE_CATALOG } from "../../config/moduleCatalog";

export default function CajaPage() {
  return <ModuleScaffold module={MODULE_CATALOG.caja} />;
}
