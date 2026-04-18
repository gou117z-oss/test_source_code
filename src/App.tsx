import React, { useState } from "react";
import TagInputBox, { Option } from "./components/TagInputBox";

const ADVERTISERS: Option[] = [
  { id: 3,  name: "TSCY" },
  { id: 23, name: "TS協業" },
  { id: 32, name: "トウル一副業LP" },
  { id: 45, name: "サンプル広告主A" },
  { id: 67, name: "サンプル広告主B" },
];

const ADS: Option[] = [
  { id: 1, name: "広告A" },
  { id: 2, name: "広告B" },
  { id: 3, name: "広告C" },
];

export default function App() {
  const [selectedAdvertisers, setSelectedAdvertisers] = useState<Option[]>([
    { id: 32, name: "トウル一副業LP" },
  ]);
  const [selectedAds, setSelectedAds] = useState<Option[]>([]);

  return (
    <div style={{ padding: 40, maxWidth: 600 }}>
      <TagInputBox
        label="広告主"
        options={ADVERTISERS}
        value={selectedAdvertisers}
        onChange={setSelectedAdvertisers}
      />
      <TagInputBox
        label="広告"
        options={ADS}
        value={selectedAds}
        onChange={setSelectedAds}
      />
    </div>
  );
}
